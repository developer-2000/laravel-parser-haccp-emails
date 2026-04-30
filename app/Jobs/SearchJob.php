<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\ParsingState;
use App\Services\AppLogger;
use App\Services\LeadClassifier;
use App\Services\PlaywrightService;
use App\Services\SerpParser;
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
     * Random jitter (секунды) перед каждым fetch'ем SERP. Размазывает
     * исходящие запросы во времени, чтобы DDG не видел чёткого периода.
     */
    private const SERP_JITTER = [1, 2];

    /**
     * Расписание задержек (в секундах) между попытками после сбоя итерации.
     *
     * Используется в backoff() — Laravel-механизм. После 1-го фейла ждём 3 сек,
     * после 2-го — 8 сек. Если 3-я попытка тоже упадёт — Laravel вызовет failed().
     */
    private const RETRY_BACKOFF_SCHEDULE = [3, 8];

    /**
     * Delay-окно (секунды) перед переходом к следующей странице SERP.
     * Не даёт долбить DDG плотным предсказуемым потоком.
     */
    private const NEXT_ITER_DELAY = [2, 5];

    /**
     * Максимум попыток обработки одной итерации (initial + 2 retry).
     * Управляет Laravel: при throw из handle() job уезжает в очередь с задержкой
     * из backoff(); после исчерпания — вызывается failed().
     */
    public int $tries = 3;

    /**
     * Параметры запуска: searchQueryId, textQuery, languageCode/Id, typeBusinessId,
     * iteration, nextPageParams. Подробнее — в SearchJobInput.
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
        PlaywrightService $playwright,
        LeadClassifier $classifier,
        SerpParser $serpParser
    ): void {
        $this->logger = $logger;

        $this->logger->writeFile("ИТЕРАЦИЯ = {$this->input->iteration} попытка={$this->attempts()}", 'search.log');

        try {
            sleep(rand(...self::SERP_JITTER));

            // 1 Получение HTML страницы выдачи DuckDuckGo для текущей итерации.
            $html = $this->fetchSerpHtml($playwright);

            $bytes = strlen($html);
            $this->logger->writeFile("[fetch] bytes={$bytes}", 'search.log');

            // Защита от anomaly-страниц DDG (блокировка / "Unfortunately, bots use DDG too..."):
            //   - bytes < 5000 — слишком короткий ответ для нормальной SERP.
            //   - 'captcha' в тексте — явный маркер.
            //   - НЕТ result__a И НЕТ nav-link — это не страница выдачи вообще
            //     (структура DDG SERP всегда содержит хотя бы одно из них).
            // Все три случая throw'ятся → Laravel retry через backoff(). Это критично:
            // без этой проверки 14k-байтная anomaly-страница без result__a доходила
            // до markCompleted и ложно ставила completion_status=1.
            $isAnomaly = $bytes < 5000
                || str_contains($html, 'captcha')
                || (!str_contains($html, 'result__a') && !str_contains($html, 'nav-link'));
            if ($isAnomaly) {
                $this->dumpEmptyPageHtml($html);
                throw new \Exception('BLOCK_OR_EMPTY');
            }

            $newLinks = $this->extractAndDedupe($html, $serpParser);

            // Пустая страница — нормальная ситуация на дальних страницах SERP:
            // все URL либо мусор, либо уже в companies. Это НЕ повод останавливаться —
            // на следующих страницах могут быть новые домены. Идём дальше.
            if (empty($newLinks)) {
                $this->logger->writeFile('[empty-page] нет новых ссылок после dedupe — идём на следующую страницу', 'search.log');
                $this->dumpEmptyPageHtml($html);
            } else {
                $qualified = $this->filterByClassifier($newLinks, $classifier);
                if (!empty($qualified)) {
                    $this->dispatchCrawlJobs($qualified);
                }
            }

            $this->scheduleNextIteration($html, $serpParser);

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
     * Пишет финальный [ПРОВАЛ]-лог с iteration и причиной. Сам job уже
     * лежит в failed_jobs — его видно в Horizon. ParsingState содержит
     * cursor с предыдущей успешной итерации (last scheduleNextIteration),
     * поэтому повторный makeQuery возобновит парсинг ровно с той страницы,
     * которая была отправлена этой упавшей попытке.
     */
    public function failed(\Throwable $e): void
    {
        app(AppLogger::class)->writeFile(
            "[ПРОВАЛ] итерация={$this->input->iteration} попытки исчерпаны: {$e->getMessage()}",
            'search.log'
        );
    }

    /**
     * Получение HTML страницы выдачи DuckDuckGo для текущей итерации.
     *
     * Выбор режима запроса:
     *
     *   - Первая итерация (iteration === 0) или нет данных пагинации
     *     → bare search: открываем DDG /html/ с параметром q.
     *
     *   - Все следующие итерации → берём hidden-поля формы Next,
     *     полученные из предыдущей страницы выдачи (q, s, dc, v, o,
     *     api и т.д.), и POST'им их обратно в DDG как продолжение
     *     пагинации. Параметр `s` — смещение результатов (offset),
     *     по нему DDG понимает, какую страницу отдать.
     *
     * Сам HTTP-запрос идёт через Playwright (headless-браузер),
     * чтобы пройти JS-проверки DDG и не словить captcha.
     */
    private function fetchSerpHtml(PlaywrightService $playwright): string
    {
        $isNextPage = $this->input->nextPageParams !== null;

        try {
            if ($isNextPage) {
                // следующая страница — переиспользуем форму Next предыдущей итерации
                return $playwright->nextPage($this->input->nextPageParams, $this->input->languageCode);
            }

            // первая страница выдачи — обычный поиск по строке запроса
            return $playwright->search($this->input->textQuery, $this->input->languageCode);
        }
        catch (\Throwable $e) {
            $this->logPlaywrightError($isNextPage ? 'nextPage' : 'search', $e);
            // прокидываем дальше — handle() поймает и сделает retry по своей логике
            throw $e;
        }
    }

    /**
     * Запись подробного лога ошибки Playwright в отдельный файл playwright_errors.log.
     *
     * Что попадает в запись:
     *   - время (добавляет AppLogger автоматически)
     *   - что делали (mode = search | nextPage)
     *   - у кого (searchQueryId, textQuery, iteration, retry)
     *   - что произошло (класс исключения, сообщение, файл и строка, stack trace)
     *   - параметры пагинации, если упал nextPage (для воспроизведения)
     */
    private function logPlaywrightError(string $mode, \Throwable $e): void
    {
        $payload = [
            'event'         => 'playwright_failure',
            'mode'          => $mode,
            'searchQueryId'    => $this->input->searchQueryId,
            'textQuery'     => $this->input->textQuery,
            'iteration'     => $this->input->iteration,
            'attempt'       => $this->attempts(),
            'nextPageParams' => $this->input->nextPageParams,
            'exception' => [
                'class'   => get_class($e),
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ],
            'trace' => $e->getTraceAsString(),
        ];

        $this->logger->writeFile($payload, 'playwright_errors.log');
    }

    /**
     * Поставить SearchJob в очередь search со случайной задержкой rand($minDelay, $maxDelay) секунд.
     *
     * Используется при retry-перепланировании после сбоя итерации и при переходе
     * к следующей странице SERP. Случайный jitter в задержке нужен, чтобы не
     * долбить DDG плотным предсказуемым потоком и не словить блокировку.
     */
    private function dispatchAfter(SearchJobInput $input, int $minDelay, int $maxDelay): void
    {
        self::dispatch($input)
            ->delay(now()->addSeconds(rand($minDelay, $maxDelay)))
            ->onQueue('search');
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
     * Пометить парсинг этого сета как завершённый (completion_status = 1).
     *
     * Вызывается, когда SerpParser::extractNextPageParams вернул null —
     * это значит, формы Next в SERP больше нет и мы дошли до конца выдачи.
     * После этого повторный makeQuery вернёт «Этот парсинг завершен»
     * и не запустит ничего лишнего.
     */
    private function markCompleted(): void
    {
        $this->updateState([
            'completion_status' => 1,
            'next_page_params'  => null,
        ]);
    }

    /**
     * Сохранить копию HTML SERP-страницы в storage/logs для отладки.
     *
     * Зовётся когда фактически парсер не нашёл ни одной ссылки или формы Next:
     * пользователь сможет открыть файл и посмотреть, что DDG реально вернул
     * (anomaly-страница / другая разметка / пустая выдача / captcha).
     */
    private function dumpEmptyPageHtml(string $html): void
    {
        $filename = 'serp_dump_' . date('Ymd_His') . '_iter' . $this->input->iteration . '.html';
        $path = storage_path('logs/' . $filename);
        @file_put_contents($path, $html);
        $this->logger->writeFile("[dump] HTML сохранён: {$filename}", 'search.log');
    }

    /**
     * Создать или обновить запись ParsingState для текущего сета
     * (language_id + type_business_id) указанным набором полей.
     *
     * Используется в scheduleNextIteration (cursor на следующую необработанную
     * страницу) и в markCompleted (status=1). Через updateOrCreate, чтобы
     * запись создавалась автоматически на первой же scheduleNextIteration —
     * не нужно делать явный $state->save() в QueryController.
     *
     * Если languageId или typeBusinessId не заданы — тихо пропускаем:
     * нечем индексировать запись.
     */
    private function updateState(array $fields): void
    {
        if ($this->input->languageId === null || $this->input->typeBusinessId === null) {
            return;
        }

        ParsingState::updateOrCreate(
            [
                'language_id'      => $this->input->languageId,
                'type_business_id' => $this->input->typeBusinessId,
            ],
            $fields
        );
    }

    /**
     * Планирование следующей итерации обхода выдачи.
     *
     * Что делает:
     *   - Парсит из текущего HTML hidden-поля формы Next (q, s, dc, v, o, api…).
     *   - Если формы нет → это последняя страница выдачи, тихо завершаемся.
     *   - Если форма есть → диспатчит SearchJob с iteration+1 и параметрами
     *     пагинации, retry сбрасывается в 0, очередь search, задержка 2–5 сек
     *     (чтобы не долбить DDG плотным потоком).
     */
    private function scheduleNextIteration(string $html, SerpParser $serpParser): void
    {
        $nextPageParams = $serpParser->extractNextPageParams($html);

        if ($nextPageParams === null) {
            // Диагностика: почему extractNextPageParams вернул null.
            //   - Если в HTML вообще нет nav-link — это либо anomaly-страница DDG,
            //     либо настоящий конец выдачи (DDG не показывает форму Next).
            //   - Если nav-link есть, но parser его не понял — значит DDG
            //     поменял разметку, нужен фикс SerpParser.
            $hasNavLink = str_contains($html, 'nav-link') ? 'есть' : 'НЕТ';
            $hasResultA = str_contains($html, 'result__a') ? 'есть' : 'НЕТ';
            $this->logger->writeFile(
                "[done] последняя страница выдачи — парсинг завершён (nav-link={$hasNavLink}, result__a={$hasResultA})",
                'search.log'
            );
            $this->dumpEmptyPageHtml($html);
            $this->markCompleted();
            return;
        }

        // Сохранить cursor "следующая необработанная страница" в БД ДО dispatch.
        // Тогда при /makeQuery следующий resume стартует именно с неё, без повтора
        // только что обработанной. Если упадём после save но до dispatch —
        // максимум потеряем одно scheduling, но cursor корректный.
        $this->updateState(['next_page_params' => $nextPageParams]);

        $this->logger->writeFile("[next] s={$nextPageParams['s']}", 'search.log');

        $this->dispatchAfter($this->input->next($nextPageParams), ...self::NEXT_ITER_DELAY);
    }

    /**
     * Извлечение ссылок из SERP + 3-уровневая дедупликация.
     *
     * Pipeline:
     *   raw → normalized → unique-in-batch → after-trash → new-unique (vs DB)
     *
     * Каждый шаг логирует свой счётчик в search.log, чтобы в логе было видно,
     * на каком этапе сколько ссылок отвалилось.
     */
    private function extractAndDedupe(string $html, SerpParser $serpParser): array
    {
        $rawLinks = $serpParser->extractLinks($html);
        $rawCount = count($rawLinks);

        if ($rawCount === 0) {
            $this->logger->writeFile('[pipeline] raw=0 — DDG не вернул ни одной ссылки в выдаче', 'search.log');
            return [];
        }

        // 1. URL → scheme://host (без path/query/fragment).
        // 2. dedupe внутри текущей SERP-страницы.
        // 3. trash-фильтр (агрегаторы / магазины / оборудование).
        // 4. dedupe относительно companies — оставляем только новые.
        $normalized = $this->normalizeBatch($rawLinks);
        $unique     = $this->dedupeInBatch($normalized);
        $afterTrash = $this->filterTrash($unique);
        $newLinks   = $this->dedupeAgainstDb($afterTrash);

        $this->logger->writeFile(
            "[pipeline] raw={$rawCount} unique=" . count($unique)
            . ' after-trash=' . count($afterTrash)
            . ' new=' . count($newLinks),
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

        $existing = Company::whereIn('url', $links)->pluck('url')->toArray();

        $newLinks = [];
        $duplicates = [];

        foreach ($links as $link) {
            if (in_array($link, $existing, true)) {
                $duplicates[] = $link;
                continue;
            }
            $newLinks[] = $link;
        }

        if ($duplicates) {
            $this->logger->writeFile(
                '[db-dup] (' . count($duplicates) . ') ' . implode(', ', $duplicates),
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
