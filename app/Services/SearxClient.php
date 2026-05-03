<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Клиент к локальному SearXNG (мета-поисковик через docker network).
 *
 * SearXNG параллельно опрашивает несколько search-engines (Brave, Bing, DDG,
 * Mojeek, Qwant — список настраивается в docker/searxng/settings.yml),
 * объединяет и дедупит результаты. Если один engine блокирует datacenter IP —
 * остальные продолжают работать. Это полностью убирает проблему anti-bot
 * блокировок DDG, с которой мы упирались в headless Chromium.
 *
 * Endpoint: POST http://searxng:8080/search?format=json
 *   q         — поисковый запрос (обязательно)
 *   format    — json (для программного парсинга)
 *   pageno    — номер страницы (1, 2, 3, ...) — searxng пагинирует
 *   language  — короткий код 'de' / 'en' / 'fr' / 'all' (для всех)
 *
 * Возвращает массив { results: [{ url, title, content, engine, ... }, ...] }.
 */
class SearxClient
{
    private const ENDPOINT = 'http://searxng:8080/search';

    /**
     * Таймаут одного HTTP-запроса к SearXNG. Внутри SearXNG параллельно
     * опрашивает 5+ engines с собственными таймаутами — суммарно ~20 сек
     * worst-case. 60 сек запас на медленный engine + сетевые задержки.
     */
    private const TIMEOUT_SECONDS = 60;

    /**
     * Жёсткий потолок страниц SearXNG — safety-net против бесконечного цикла,
     * НЕ ожидаемое количество страниц. Реальная остановка — естественная:
     *   - SearXNG вернул пустую страницу (raw=0) → выдача исчерпана;
     *   - все URL на странице оказались дубликатами уже виденных.
     * Большинство запросов сами останавливаются на 15-30 страницах.
     */
    private const DEFAULT_MAX_PAGES = 100;

    /**
     * Пауза между запросами страниц SearXNG (рандомизированная).
     * Без паузы upstream-engines (brave/ddg/qwant) ловят rate-limit и
     * начинают отваливаться на page 3-4. С паузой 2-4 сек ведём себя как
     * живой пользователь, листающий выдачу — engines не баннят.
     *
     * Значения в миллисекундах для usleep().
     */
    private const PAGE_DELAY_MIN_MS = 2000;
    private const PAGE_DELAY_MAX_MS = 4000;

    /**
     * Получить список URL-результатов для поискового запроса.
     *
     * Внутри листает страницы SearXNG (pageno=1, 2, ...) пока:
     *   - не получим пустую страницу (выдача исчерпана);
     *   - все URL на странице оказались дубликатами уже виденных (нет смысла листать);
     *   - не достигнем maxPages (защита от бесконечного листания).
     *
     * @param  string        $query         поисковый запрос
     * @param  string|null   $languageCode  короткий код языка (de/en/fr/...)
     * @param  int           $maxPages      жёсткий потолок страниц (safety-net)
     * @param  \Closure|null $onPage        опционально: вызывается ПОСЛЕ каждой
     *                                      страницы с её мета-инфой. Нужно для
     *                                      стриминга логов в реальном времени —
     *                                      без callback'а вызывающий получит
     *                                      все страницы только после конца search().
     * @return array{urls: string[], pages: list<array{pageno:int,raw:int,added:int,duration_ms:int,unresponsive_engines:list<string>,new_urls:list<string>,dup_urls:list<string>}>}
     *         urls — итоговый дедуп-набор уникальных URL'ов;
     *         pages — лог по каждой странице: сколько raw / сколько новых / время / какие engines не ответили.
     *
     * @throws \RuntimeException при сетевой ошибке или 5xx от searxng
     */
    public function search(
        string $query,
        ?string $languageCode = null,
        int $maxPages = self::DEFAULT_MAX_PAGES,
        ?\Closure $onPage = null
    ): array {
        $language = $languageCode ?: 'all';
        $seen = [];
        $urls = [];
        $pagesMeta = [];

        for ($pageno = 1; $pageno <= $maxPages; $pageno++) {
            // Перед каждой страницей кроме первой — рандомная пауза.
            // Имитация живого пользователя: листаем не быстрее ~2-4 сек.
            if ($pageno > 1) {
                $delayMs = random_int(self::PAGE_DELAY_MIN_MS, self::PAGE_DELAY_MAX_MS);
                usleep($delayMs * 1000);
            }

            $page = $this->fetchPage($query, $language, $pageno);

            $rawCount = count($page['urls']);
            $newUrls = [];
            $dupUrls = [];
            foreach ($page['urls'] as $url) {
                if (isset($seen[$url])) {
                    $dupUrls[] = $url;
                    continue;
                }
                $seen[$url] = true;
                $urls[] = $url;
                $newUrls[] = $url;
            }
            $newOnPage = count($newUrls);

            $pageMeta = [
                'pageno'               => $pageno,
                'raw'                  => $rawCount,
                'added'                => $newOnPage,
                'duration_ms'          => $page['duration_ms'],
                'unresponsive_engines' => $page['unresponsive_engines'],
                // Полные списки URL для прозрачного логирования: что searxng
                // реально вернул на этой странице (new — впервые увиденные за
                // этот search, dup — уже встречались на предыдущих страницах).
                'new_urls'             => $newUrls,
                'dup_urls'             => $dupUrls,
            ];
            $pagesMeta[] = $pageMeta;

            // Стриминг логов: уведомляем вызывающего сразу после страницы,
            // чтобы строка ушла в search.log в реальном времени, а не пакетом
            // в самом конце search().
            if ($onPage !== null) {
                $onPage($pageMeta);
            }

            if ($rawCount === 0) {
                // Пустая страница — конец выдачи.
                break;
            }

            if ($newOnPage === 0) {
                // Все URL уже видели — дальше листать бесполезно.
                break;
            }
        }

        return [
            'urls'  => $urls,
            'pages' => $pagesMeta,
        ];
    }

    /**
     * Запросить одну страницу выдачи SearXNG.
     *
     * @return array{urls:string[], duration_ms:int, unresponsive_engines:list<string>}
     */
    private function fetchPage(string $query, string $language, int $pageno): array
    {
        $startedAt = microtime(true);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->asForm()
                ->post(self::ENDPOINT, [
                    'q'        => $query,
                    'format'   => 'json',
                    'pageno'   => $pageno,
                    'language' => $language,
                ]);
        } catch (ConnectionException $e) {
            throw new \RuntimeException(
                'searxng unreachable: ' . $e->getMessage(),
                0,
                $e
            );
        }

        if (!$response->ok()) {
            throw new \RuntimeException(
                'searxng returned ' . $response->status() . ': ' . substr($response->body(), 0, 500)
            );
        }

        $data = $response->json();
        $results = $data['results'] ?? [];

        $urls = array_values(array_filter(array_map(
            fn ($r) => $r['url'] ?? null,
            $results
        )));

        // unresponsive_engines в JSON — список [engine_name, reason] для тех,
        // кто на этом запросе не ответил (timeout / rate-limit / etc).
        $unresponsive = array_map(
            fn ($pair) => is_array($pair) ? ($pair[0] ?? '?') : (string) $pair,
            $data['unresponsive_engines'] ?? []
        );

        return [
            'urls'                 => $urls,
            'duration_ms'          => (int) round((microtime(true) - $startedAt) * 1000),
            'unresponsive_engines' => $unresponsive,
        ];
    }
}
