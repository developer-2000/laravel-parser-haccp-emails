<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\SearchQuery;
use App\Services\AppLogger;
use App\Services\LeadClassifier;
use App\Services\SearxClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Queue;

class SearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Расписание задержек (в секундах) между попытками после сбоя.
     *
     * После 1-го фейла ждём 3 сек, после 2-го — 8 сек. Если 3-я попытка тоже
     * упадёт — Laravel вызовет failed() и job попадёт в failed_jobs.
     */
    private const RETRY_BACKOFF_SCHEDULE = [3, 8];

    /**
     * Максимум попыток обработки одной итерации (initial + 2 retry).
     * Управляет Laravel: при throw из handle() job уезжает в очередь с задержкой
     * из backoff(); после исчерпания — вызывается failed().
     */
    public int $tries = 3;

    /**
     * Параметры запуска: searchQueryId, textQuery, languageCode, typeBusinessId.
     * Подробнее — в SearchJobInput.
     */
    public SearchJobInput $input;

    /**
     * Логгер для записи в search.log / playwright_errors.log.
     *
     * Остаётся null до начала handle() — там присваивается резолвнутый из DI
     * экземпляр и используется всеми приватными методами через $this->logger.
     * Поле НЕ должно попадать в payload очереди: Job всегда сериализуется ДО
     * handle (когда $this->logger === null), а после handle ре-сериализации не
     * происходит — failed_jobs хранят исходный payload.
     */
    private ?AppLogger $logger = null;

    public function __construct(SearchJobInput $input)
    {
        $this->input = $input;
    }

    public function handle(
        AppLogger $logger,
        SearxClient $searx,
        LeadClassifier $classifier
    ): void {
        $this->logger = $logger;

        $this->logger->writeFile(
            "[START] q=\"{$this->input->textQuery}\" lang={$this->input->languageCode} попытка={$this->attempts()}",
            'search.log'
        );

        try {
            // Один вызов searxng → мета-поисковик параллельно опрашивает Brave,
            // Bing, DuckDuckGo, Mojeek, Qwant и возвращает объединённую выдачу.
            // Если один engine блокирует — остальные продолжают работать.
            // Внутри клиент листает страницы pageno=1,2,3,... пока есть новые URL.
            //
            // Callback $onPage пишет лог по каждой странице сразу после её
            // получения — без него все строки `page=N raw=...` приходили бы
            // в search.log пачкой в конце search() (с одной timestamp), а нам
            // нужно видеть прогресс в реальном времени.
            $startedAt = microtime(true);
            $result = $searx->search(
                $this->input->textQuery,
                $this->input->languageCode,
                onPage: function (array $p): void {
                    $unresp = empty($p['unresponsive_engines'])
                        ? ''
                        : ' unresponsive=' . implode(',', $p['unresponsive_engines']);
                    $dupCount = count($p['dup_urls']);
                    $this->logger->writeFile(
                        "  page={$p['pageno']} raw={$p['raw']} new={$p['added']} dup={$dupCount} ({$p['duration_ms']}ms){$unresp}",
                        'search.log'
                    );
                    if (!empty($p['new_urls'])) {
                        $this->logger->writeFile(
                            '    new (' . count($p['new_urls']) . '): ' . implode(', ', $p['new_urls']),
                            'search.log'
                        );
                    }
                    if (!empty($p['dup_urls'])) {
                        $this->logger->writeFile(
                            '    dup-from-prev-page (' . count($p['dup_urls']) . '): ' . implode(', ', $p['dup_urls']),
                            'search.log'
                        );
                    }
                }
            );
            $rawLinks = $result['urls'];
            $duration = round(microtime(true) - $startedAt, 2);

            $this->logger->writeFile(
                "[Сбор] Найдено уникальных URL: " . count($rawLinks)
                . ". Время: {$duration} сек. Обработано страниц: " . count($result['pages']) . '.',
                'search.log'
            );

            if (empty($rawLinks)) {
                // Пустой массив от searxng — все engines одновременно либо
                // отказали, либо ничего не нашли. Throw → Laravel retry.
                throw new \Exception('SEARX_EMPTY');
            }

            // Здесь по факту возвращаетса масив годящихся url с текущей страницы
            $newLinks = $this->extractAndDedupe($rawLinks);

            // Массив годных url пуст
            if (empty($newLinks)) {
                $this->logger->writeFile('[empty] после фильтров не осталось новых URL', 'search.log');
            }
            // Массив годных url ПОЛОН
            else {
                $qualified = $this->filterByClassifier($newLinks, $classifier);
                if (!empty($qualified)) {
                    $this->dispatchCrawlJobs($qualified);
                }
            }

            // Полный обход searxng в одном вызове — после успеха фиксируем
            // завершение: ставим search_query.completion_status = 1, чтобы UI
            // видел что этот запрос отработан. Если handle() упадёт раньше —
            // флаг останется 0, и /makeQuery можно запустить повторно.
            SearchQuery::where('id', $this->input->searchQueryId)
                ->update(['completion_status' => 1]);

            $this->logger->writeFile('[done] парсинг завершён', 'search.log');

        } catch (\Throwable $e) {
            // Логируем диагностику каждой failed-попытки и перебрасываем выше —
            // Laravel сам сделает retry через backoff() и failed_jobs / failed().
            $this->logger->writeFile("[ОШИБКА] {$e->getMessage()} попытка={$this->attempts()}", 'search.log');
            throw $e;
        }
    }

    /**
     * Расписание задержек между retry-попытками. Возвращает массив:
     * [N сек после 1-го фейла, M сек после 2-го фейла, ...].
     */
    public function backoff(): array
    {
        return self::RETRY_BACKOFF_SCHEDULE;
    }

    /**
     * Вызывается Laravel'ом, когда все $tries попыток исчерпаны.
     *
     * Пишет финальный [ПРОВАЛ]-лог. Сам Job уже лежит в failed_jobs —
     * виден в Horizon UI, можно перезапустить через `php artisan queue:retry`.
     */
    public function failed(\Throwable $e): void
    {
        app(AppLogger::class)->writeFile(
            "[ПРОВАЛ] q=\"{$this->input->textQuery}\" попытки исчерпаны: {$e->getMessage()}",
            'search.log'
        );
    }

    /**
     * Поставить пачку CrawlJob в очередь crawl одним bulk-push'ем.
     *
     * Зачем: при цикле `CrawlJob::dispatch(...)->onQueue('crawl')` каждый URL
     * порождает отдельный round-trip к очереди (для Redis это N * LPUSH,
     * для DB-driver'а — N отдельных INSERT'ов). Queue::bulk укладывает все
     * job'ы за один pipeline / один batch-INSERT.
     *
     * @param  string[]  $urls  Уже qualified URL — каждый превратится в один CrawlJob.
     * @return int              Количество поставленных задач.
     */
    private function dispatchCrawlJobs(array $urls): int
    {
        $jobs = array_map(
            fn (string $url) => new CrawlJob($url, $this->input->searchQueryId, $this->input->typeBusinessId),
            $urls
        );

        Queue::bulk($jobs, '', 'crawl');

        $this->logger->writeFile(
            '→ crawl (' . count($jobs) . ') ' . implode(', ', $urls),
            'search.log'
        );

        return count($jobs);
    }

    /**
     * Прогнать массив URL через 4 этапа фильтрации:
     *   1. URL → scheme://host (без path/query/fragment) — нормализация.
     *   2. dedupe внутри пачки.
     *   3. trash-фильтр (агрегаторы / магазины / оборудование).
     *   4. dedupe relative companies — оставляем только URL'ы, которых ещё
     *      нет в БД.
     *
     * @param  string[]  $rawLinks  URL'ы от searxng (full-DDG).
     * @return string[]              URL'ы прошедшие все 4 фильтра.
     */
    private function extractAndDedupe(array $rawLinks): array
    {
        $rawCount = count($rawLinks);

        $normalized = $this->normalizeBatch($rawLinks);
        $unique     = $this->dedupeInBatch($normalized);
        $afterTrash = $this->filterTrash($unique);
        $newLinks   = $this->dedupeAgainstDb($afterTrash);

        $this->logger->writeFile(
            '[Конвейер фильтров]' . PHP_EOL
            . "    Получено URL:           {$rawCount}" . PHP_EOL
            . '    После нормализации:     ' . count($unique) . PHP_EOL
            . '    После фильтра мусора:   ' . count($afterTrash) . PHP_EOL
            . '    Новых для базы:         ' . count($newLinks),
            'search.log'
        );

        return $newLinks;
    }

    /**
     * Шаг 1 — нормализация URL до канонической формы scheme://host.
     *
     * Зачем: без этого "site.de/impressum/", "site.de/kontakt/" и "site.de/"
     * попадут в БД как 3 разные компании.
     *
     * www оставляем как есть — иногда www.site.de и site.de реально разные
     * (отдельные SSL/redirect, разные сайты).
     */
    private function normalizeBatch(array $rawLinks): array
    {
        return array_values(array_filter(array_map(
            fn($url) => $this->normalizeUrl($url),
            $rawLinks
        )));
    }

    /**
     * Шаг 2 — дедуп внутри текущего батча.
     *
     * После нормализации схлопываются варианты impressum/kontakt/root одного домена.
     */
    private function dedupeInBatch(array $normalized): array
    {
        return array_values(array_unique($normalized));
    }

    /**
     * Шаг 3 — фильтр мусорных URL: магазины, оборудование, агрегаторы, документы, ассоциации.
     *
     * Сами правила см. в isTrashUrl(). В лог отдельно пишется список отброшенных,
     * чтобы было видно, какие домены отсеялись.
     */
    private function filterTrash(array $links): array
    {
        $clean = [];
        $trashed = [];

        foreach ($links as $link) {
            if ($this->isTrashUrl($link)) {
                $trashed[] = $link;
                continue;
            }
            $clean[] = $link;
        }

        if ($trashed) {
            $this->logger->writeFile(
                '[trash] (' . count($trashed) . ') ' . implode(', ', $trashed),
                'search.log'
            );
        }

        return $clean;
    }

    /**
     * Шаг 4 — дедуп с БД: одним запросом проверяем, какие URL уже сохранены ранее.
     *
     * Один SELECT WHERE IN на всю пачку — не N запросов по одному.
     * Дубликаты тоже логируются списком — для разбора, что приходит повторно.
     */
    private function dedupeAgainstDb(array $links): array
    {
        if (empty($links)) {
            return [];
        }

        // Сравниваем по host, а не по полной строке: SearXNG между прогонами
        // может вернуть один и тот же сайт то с http://, то с https:// —
        // тогда whereIn по полному url промахивается, и мы считаем уже
        // сохранённую компанию "новой". Берём оба scheme-варианта в IN,
        // плюс сами оригинальные строки на всякий случай.
        $hosts = [];
        $variants = [];
        foreach ($links as $link) {
            $host = parse_url($link, PHP_URL_HOST);
            if ($host !== null) {
                $hosts[$link]   = $host;
                $variants[]     = 'http://'  . $host;
                $variants[]     = 'https://' . $host;
            }
            $variants[] = $link;
        }
        $variants = array_values(array_unique($variants));

        $existingUrls  = Company::whereIn('url', $variants)->pluck('url')->toArray();
        $existingHosts = [];
        foreach ($existingUrls as $u) {
            $h = parse_url($u, PHP_URL_HOST);
            if ($h !== null) {
                $existingHosts[$h] = true;
            }
        }

        $newLinks = [];
        $duplicates = [];

        foreach ($links as $link) {
            $host = $hosts[$link] ?? null;
            if ($host !== null && isset($existingHosts[$host])) {
                $duplicates[] = $link;
                continue;
            }
            $newLinks[] = $link;
        }

        if ($duplicates) {
            $this->logger->writeFile(
                '[Уже в базе] Совпадений с companies: ' . count($duplicates) . '.' . PHP_EOL
                . '    ' . implode(PHP_EOL . '    ', $duplicates),
                'search.log'
            );
        }

        return $newLinks;
    }

    /**
     * Ранний URL-фильтр через LeadClassifier.
     *
     * Считает tier для каждой ссылки в URL-only режиме (без HTML).
     * Tier=0 → отбрасываем (заведомый мусор: shop, maschinen, verband и т.п.).
     * Tier>=1 → передаём дальше в CrawlJob, где будет финальная классификация
     * с учётом текста главной страницы.
     *
     * В лог пишем сводку по tier'ам и список отброшенных, чтобы было видно,
     * какие домены отсекаются на этом этапе.
     */
    private function filterByClassifier(array $links, LeadClassifier $classifier): array
    {
        $qualified = [];
        $dropped = [];
        $tierStats = [0 => 0, 1 => 0, 2 => 0, 3 => 0];

        foreach ($links as $link) {
            $tier = $classifier->classify($link, null, $this->input->typeBusinessId);
            $tierStats[$tier]++;

            if (!$classifier->passesUrlFilter($tier)) {
                $dropped[] = $link;
                continue;
            }
            $qualified[] = $link;
        }

        if ($dropped) {
            $this->logger->writeFile(
                '[classifier-drop] (' . count($dropped) . ') ' . implode(', ', $dropped),
                'search.log'
            );
        }
        $this->logger->writeFile(
            '[classifier] t0=' . $tierStats[0] . ' t1=' . $tierStats[1] . ' t2=' . $tierStats[2] . ' t3=' . $tierStats[3]
            . ' → qualified=' . count($qualified),
            'search.log'
        );

        return $qualified;
    }

    /**
     * Нормализация URL до канонической формы scheme://host.
     *
     * Цель: схлопнуть варианты типа site.de/impressum/, site.de/kontakt/, site.de/?q=foo
     * в единый "site.de" — иначе один производитель попадает в БД 3+ раза по разным URL.
     *
     * Что делает:
     *   - оставляет только scheme + host (без path, query, fragment)
     *   - host приводит к нижнему регистру (DNS case-insensitive)
     *   - убирает trailing dot ("example.de." → "example.de")
     *
     * Что НЕ делает (намеренно):
     *   - не убирает www. — www.site.de и site.de могут быть разными ресурсами
     *     (разные SSL-сертификаты, отдельные редиректы, в крайнем случае разные сайты).
     *
     * @return string|null null если URL невалидный (нет host)
     */
    private function normalizeUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (!isset($parts['host'])) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = rtrim(strtolower($parts['host']), '.');

        // IDN-домены (Umlaut и кириллица) → punycode-форма (xn--...).
        // Без этого Guzzle/cURL не может разрешить DNS, и fetchHomepage
        // получает пустой ответ → "EMPTY HTML" в crawl.log.
        // Требует PHP-extension intl (libicu); если её нет — оставляем host
        // как есть, чтобы не падать в окружении без intl.
        if (function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7f]/', $host)) {
            $ascii = @idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii !== false) {
                $host = $ascii;
            }
        }

        return $scheme . '://' . $host;
    }

    /**
     * Скомпилированный regex отбрасывания URL.
     *
     * Кэшируется на уровне класса (static) — собирается один раз на воркер,
     * между всеми job'ами в этом процессе переиспользуется. Lazy-init
     * происходит в trashUrlPattern() при первом обращении.
     */
    private static ?string $compiledTrashPattern = null;

    /**
     * Фильтр URL по группам "мусорных" паттернов из config/site/trash_url.php.
     * Цель — оставить только реальных производителей, отрезав магазины,
     * оборудование, агрегаторы, документы и ассоциации.
     *
     * ВАЖНО: /produkte/ и /products/ намеренно НЕ входят ни в одну группу —
     * у настоящих производителей это страница ассортимента, она нам нужна.
     */
    private function isTrashUrl(string $url): bool
    {
        return (bool) preg_match($this->trashUrlPattern(), $url);
    }

    /**
     * Возвращает скомпилированный regex для isTrashUrl.
     *
     * При первом вызове в рамках воркера склеивает группы из config/site/trash_url.php
     * через "|" и кэширует результат в static-свойстве; последующие вызовы
     * возвращают готовый pattern без рекомпиляции.
     */
    private function trashUrlPattern(): string
    {
        if (self::$compiledTrashPattern !== null) {
            return self::$compiledTrashPattern;
        }

        $groups = config('site.trash_url.groups', []);

        return self::$compiledTrashPattern = '#(?:' . implode('|', $groups) . ')#i';
    }
}
