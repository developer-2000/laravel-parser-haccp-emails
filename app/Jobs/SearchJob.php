<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\AppLogger;
use App\Services\LeadClassifier;
use App\Services\PlaywrightService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $searchQueryId;
    public string $textQuery;

    /**
     * Код языка ("de" / "en" / …) — нужен для регионализации поиска DuckDuckGo
     * (kl-параметр) и для записи языка к найденным компаниям.
     */
    public ?string $languageCode;

    /**
     * id типа бизнеса (type_business.id). По нему LeadClassifier выбирает
     * набор правил из config/site/meat_scoring.php.
     */
    public ?int $typeBusinessId;

    /**
     * iteration = логическая "страница" — шаг обхода выдачи.
     */
    public int $iteration;

    public int $maxIterations;
    public int $retry;

    /**
     * Готовые hidden-поля формы Next из предыдущей страницы.
     * NULL для первой итерации (тогда делаем bare search).
     *
     * @var array<string,string>|null
     */
    public ?array $nextPageParams;

    public function __construct(
        int $searchQueryId,
        string $textQuery,
        ?string $languageCode = null,
        ?int $typeBusinessId = null,
        int $iteration = 0,
        int $retry = 0,
        ?array $nextPageParams = null
    ) {
        $this->searchQueryId = $searchQueryId;
        $this->textQuery = $textQuery;
        $this->languageCode = $languageCode;
        $this->typeBusinessId = $typeBusinessId;
        $this->iteration = $iteration;
        $this->maxIterations = 3;
        $this->retry = $retry;
        $this->nextPageParams = $nextPageParams;
    }

    public function handle(
        AppLogger $logger,
        PlaywrightService $playwright,
        LeadClassifier $classifier
    ): void {

        $logger->write("ИТЕРАЦИЯ = {$this->iteration} попытка={$this->retry}", 'search.log');

        if ($this->iteration >= $this->maxIterations) {
            $logger->write("[СТОП] достигнут лимит итераций", 'search.log');
            return;
        }

        try {
            sleep(rand(1, 2));

            // 1 Получение HTML страницы выдачи DuckDuckGo для текущей итерации.
            $html = $this->fetchSerpHtml($playwright, $logger);

            $bytes = strlen($html);
            $logger->write("[2] байт={$bytes}", 'search.log');

            // ERROR - Проверка валидности ответа DuckDuckGo:
            // bytes < 5000 → ответ слишком короткий, чтобы быть нормальной
            // str_contains('captcha') → DDG отдал страницу с защитой от ботов
            // В обоих случаях бросаем BLOCK_OR_EMPTY — внешний catch в handle()
            // запустит retry с задержкой (до 3 попыток).
            if ($bytes < 5000 || str_contains($html, 'captcha')) {
                throw new \Exception('BLOCK_OR_EMPTY');
            }

            // 2 Извлечение url сайтов из SERP + 3-уровневая дедупликация.
            $newLinks = $this->extractAndDedupe($html, $logger);

            // STOP
            if (count($newLinks) === 0) {
                $logger->write("[СТОП] нет новых ссылок после дедупликации", 'search.log');
                return;
            }

            // 3 Ранний URL-фильтр через LeadClassifier — отсекаем заведомый
            //   мусор по сигналам в URL (shop, maschinen, verband и т.п.),
            //   не тратя CrawlJob на гарантированно нерелевантные сайты.
            $qualified = $this->filterByClassifier($newLinks, $classifier, $logger);

            // Если на текущей странице SERP ни одного qualified —
            // НЕ останавливаем обход выдачи. Просто пропускаем dispatch
            // и идём дальше: на следующих страницах могут быть хорошие URL.
            $dispatched = 0;
            if (empty($qualified)) {
                $logger->write("[пропуск-диспатча] на этой странице ни одного подходящего URL", 'search.log');
            } else {
                // 4 Ставим новую задачу в очередь - откроет сайт, извлечёт данные и сохранит контакты в DB
                foreach ($qualified as $link) {
                    CrawlJob::dispatch($link, $this->searchQueryId, $this->typeBusinessId)
                        ->onQueue('crawl');

                    $dispatched++;
                }
            }

            $logger->write("[3] отправлено в очередь={$dispatched}", 'search.log');

            // 5 Планируем следующую итерацию (если в выдаче есть следующая страница)
            $this->scheduleNextIteration($html, $logger);

        } catch (\Throwable $e) {
            $logger->write("[ОШИБКА] {$e->getMessage()} попытка={$this->retry}", 'search.log');

            /**
             * Retry-механизм при сбое итерации.
             *
             * Зачем:
             *   - DDG может временно отдать captcha, обрезанный ответ или
             *     уронить Playwright по timeout — это не «фатальные» ошибки,
             *     при повторе через несколько секунд обычно проходит.
             *
             * Что делаем:
             *   - До 3 повторов на одну и ту же итерацию (retry=0 → 1 → 2 → 3).
             *   - Переписпатчим тот же SearchJob с теми же параметрами
             *     (searchQueryId, textQuery, iteration, nextPageParams), но с retry+1.
             *   - Задержка 3–8 секунд — чтобы не долбить DDG сразу после блока.
             *   - return — выходим из handle, новая попытка пойдёт уже как
             *     отдельный job из очереди.
             *
             * Если retry достиг 3 — попадаем дальше в [FAIL] лог
             * и больше не пытаемся.
             */
            if ($this->retry < 3) {
                self::dispatch(
                    $this->searchQueryId,
                    $this->textQuery,
                    $this->languageCode,
                    $this->typeBusinessId,
                    $this->iteration,
                    $this->retry + 1,
                    $this->nextPageParams
                )
                    ->delay(now()->addSeconds(rand(3, 8)))
                    ->onQueue('search');

                return;
            }

            $logger->write("[ПРОВАЛ] итерация={$this->iteration} попытки исчерпаны", 'search.log');
        }
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
    private function fetchSerpHtml(PlaywrightService $playwright, AppLogger $logger): string
    {
        // mode выбираем заранее, чтобы то же значение использовать и в логе ошибки
        $mode = ($this->iteration === 0 || $this->nextPageParams === null) ? 'search' : 'nextPage';

        try {
            if ($mode === 'search') {
                // первая страница выдачи — обычный поиск по строке запроса
                return $playwright->search($this->textQuery, $this->languageCode);
            }

            // следующая страница — переиспользуем форму Next предыдущей итерации
            $sParam = $this->nextPageParams['s'] ?? '?';
            return $playwright->nextPage($this->nextPageParams, $this->languageCode);
        }
        catch (\Throwable $e) {
            $this->logPlaywrightError($logger, $mode, $e);
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
    private function logPlaywrightError(AppLogger $logger, string $mode, \Throwable $e): void
    {
        $payload = [
            'event'         => 'playwright_failure',
            'mode'          => $mode,
            'searchQueryId'    => $this->searchQueryId,
            'textQuery'     => $this->textQuery,
            'iteration'     => $this->iteration,
            'retry'         => $this->retry,
            'nextPageParams' => $this->nextPageParams,
            'exception' => [
                'class'   => get_class($e),
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ],
            'trace' => $e->getTraceAsString(),
        ];

        $logger->write($payload, 'playwright_errors.log');
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
    private function scheduleNextIteration(string $html, AppLogger $logger): void
    {
        $nextPageParams = $this->extractNextPageParams($html);

        if ($nextPageParams === null) {
            $logger->write("[следующая-страница] не найдена — последняя страница выдачи, СТОП", 'search.log');
            return;
        }

        $logger->write(
            "[следующая-страница] найдена s={$nextPageParams['s']} ключи=" . implode(',', array_keys($nextPageParams)),
            'search.log'
        );

        self::dispatch(
            $this->searchQueryId,
            $this->textQuery,
            $this->languageCode,
            $this->typeBusinessId,
            $this->iteration + 1,
            0,
            $nextPageParams
        )
            ->delay(now()->addSeconds(rand(2, 5)))
            ->onQueue('search');
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
    private function extractAndDedupe(string $html, AppLogger $logger): array
    {
        // 0. достаём сырые ссылки из HTML выдачи DuckDuckGo
        $rawLinks = $this->extractLinksSERP($html);
        $logger->write('[сырые] количество=' . count($rawLinks), 'search.log');

        // выдача пустая — дальше делать нечего
        if (empty($rawLinks)) {
            return [];
        }

        // 1. приводим каждый URL к scheme://host (убираем path/query/fragment)
        $normalized = $this->normalizeBatch($rawLinks, $logger);
        // 2. схлопываем дубли url внутри текущей пачки
        $unique     = $this->dedupeInBatch($normalized, $logger);
        // 3. отсекаем мусорные домены/пути (магазины, оборудование, агрегаторы)
        $afterTrash = $this->filterTrash($unique, $logger);
        // 4. отсеиваем то, что уже сохранено в БД, и возвращаем только новые URL
        return $this->dedupeAgainstDb($afterTrash, $logger);
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
    private function normalizeBatch(array $rawLinks, AppLogger $logger): array
    {
        $normalized = array_values(array_filter(array_map(
            fn($url) => $this->normalizeUrl($url),
            $rawLinks
        )));

        $logger->write('[нормализовано] количество=' . count($normalized), 'search.log');

        return $normalized;
    }

    /**
     * Шаг 2 — дедуп внутри текущего батча.
     *
     * После нормализации схлопываются варианты impressum/kontakt/root одного домена.
     */
    private function dedupeInBatch(array $normalized, AppLogger $logger): array
    {
        $unique = array_values(array_unique($normalized));
        $merged = count($normalized) - count($unique);

        $logger->write('[уникальные-в-пачке] количество=' . count($unique) . " схлопнуто={$merged}", 'search.log');

        return $unique;
    }

    /**
     * Шаг 3 — фильтр мусорных URL: магазины, оборудование, агрегаторы, документы, ассоциации.
     *
     * Сами правила см. в isTrashUrl(). В лог отдельно пишется список отброшенных,
     * чтобы было видно, какие домены отсеялись.
     */
    private function filterTrash(array $links, AppLogger $logger): array
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
            $logger->write(
                '[trashed] count=' . count($trashed) . ' list=' . json_encode($trashed, JSON_UNESCAPED_SLASHES),
                'search.log'
            );
        }
        $logger->write('[after-trash] count=' . count($clean), 'search.log');

        return $clean;
    }

    /**
     * Шаг 4 — дедуп с БД: одним запросом проверяем, какие URL уже сохранены ранее.
     *
     * Один SELECT WHERE IN на всю пачку — не N запросов по одному.
     * Дубликаты тоже логируются списком — для разбора, что приходит повторно.
     */
    private function dedupeAgainstDb(array $links, AppLogger $logger): array
    {
        if (empty($links)) {
            $logger->write('[new-unique] count=0', 'search.log');
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
            $logger->write(
                '[db-duplicates] count=' . count($duplicates) . ' list=' . json_encode($duplicates, JSON_UNESCAPED_SLASHES),
                'search.log'
            );
        }
        $logger->write('[new-unique] count=' . count($newLinks), 'search.log');

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
    private function filterByClassifier(array $links, LeadClassifier $classifier, AppLogger $logger): array
    {
        $qualified = [];
        $dropped = [];
        $tierStats = [0 => 0, 1 => 0, 2 => 0, 3 => 0];

        foreach ($links as $link) {
            $tier = $classifier->classify($link, null, $this->typeBusinessId);
            $tierStats[$tier]++;

            if ($tier === 0) {
                $dropped[] = $link;
                continue;
            }
            $qualified[] = $link;
        }

        if ($dropped) {
            $logger->write(
                '[classifier-dropped] count=' . count($dropped) . ' list=' . json_encode($dropped, JSON_UNESCAPED_SLASHES),
                'search.log'
            );
        }
        $logger->write(
            '[classifier-stats] t0=' . $tierStats[0] . ' t1=' . $tierStats[1] . ' t2=' . $tierStats[2] . ' t3=' . $tierStats[3],
            'search.log'
        );
        $logger->write('[qualified] count=' . count($qualified), 'search.log');

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

        return $scheme . '://' . $host;
    }

    /**
     * Извлечение ссылок из SERP DuckDuckGo HTML.
     */
    private function extractLinksSERP(string $html): array
    {
        $links = [];

        preg_match_all(
            '/class="result__a"[^>]*href="([^"]+)"/i',
            $html,
            $matches
        );

        foreach ($matches[1] as $href) {
            $href = html_entity_decode($href);

            if (preg_match('/uddg=([^&]+)/', $href, $m)) {
                $url = urldecode($m[1]);
                $links[] = strtok($url, '?');
            } elseif (str_starts_with($href, 'http')) {
                $links[] = strtok($href, '?');
            }
        }

        return $links;
    }

    /**
     * Извлечение всех hidden-полей формы Next из конца страницы DDG.
     * DDG /html/ оборачивает кнопку "Next" в <div class="nav-link"> с form внутри.
     * Если страниц больше нет — формы нет → возвращаем null.
     */
    private function extractNextPageParams(string $html): ?array
    {
        if (!preg_match_all('/<div\s+class="nav-link"[^>]*>(.*?)<\/div>/s', $html, $blocks)) {
            return null;
        }

        // Если nav-link несколько (Previous + Next) — берём последний (это всегда Next).
        $section = end($blocks[1]);

        preg_match_all(
            '/<input[^>]*name="([^"]+)"[^>]*value="([^"]*)"/i',
            $section,
            $inputs,
            PREG_SET_ORDER
        );

        if (empty($inputs)) {
            return null;
        }

        $params = [];
        foreach ($inputs as $input) {
            $params[$input[1]] = html_entity_decode($input[2]);
        }

        // Защита: настоящая форма Next имеет хотя бы q + s.
        if (!isset($params['q']) || !isset($params['s'])) {
            return null;
        }

        return $params;
    }

    /**
     * Фильтр URL по 6 категориям мусора. Цель — оставить только производителей мяса
     * (HACCP-релевантных), отрезав магазины оборудования, агрегаторы, документы, регуляторы.
     *
     * ВАЖНО: /produkte/ и /products/ НЕ фильтруются — у настоящих производителей это
     * страница ассортимента продукции (колбасы, ветчины, копчёности), это нам нужно.
     */
    private function isTrashUrl(string $url): bool
    {
        // 1. Глобальные платформы и job-сайты — заведомо не производители.
        $globalPlatforms = 'google\.|facebook\.|linkedin\.|youtube\.|amazon\.|wikipedia\.|instagram\.|tiktok\.|'
            . 'kununu\.|stepstone\.|indeed\.';

        // 2. B2B-каталоги, агрегаторы, бизнес-справочники, госстатистика.
        // Они содержат списки компаний, а не сами производители.
        $aggregators = 'gelbeseiten\.|wer-liefert-was\.|europages\.|kompass\.com|'
            . 'wlw\.de|firmenwissen\.de|northdata\.de|branchenbuch|'
            . 'bizdb\.|vdma-products\.|firmen-|unternehmen-|spherescout\.|'
            . 'creditreform\.|firmeneintrag|destatis\.|fleischbranche\.|'
            . 'remax\.|immobilien-|-immobilien\.|wizzair\.';

        // 3. Магазины (домены). Производитель может иметь онлайн-витрину,
        // но если он назвался "shop" в домене — это розничник, не производитель.
        // \.shop$ и \.shop/ — TLD .shop (типа torwegge.shop).
        $shopDomains = '-shop\.|shop-|shop\d+\.|-store\.|store-|-versand\.|-direkt\.|'
            . '-katalog\.|-mall\.|-onlineshop\.|onlineshop-|'
            . '\.shop/|\.shop$|\.shop\?|'
            . '-grosshandel\.|grosshandel-|-grosshandel-';

        // 4. Магазины (пути). Признаки розничной площадки в URL-пути.
        // /produkte/ и /products/ оставлены за бортом — это легитимные пути производителей.
        $shopPaths = '/shop/|/store/|/cart/|/warenkorb/|/checkout/|/kaufen/|/bestellen/|'
            . '/sortiment/|/category/|/categories/|/kategorie/|/kategorien/';

        // 5a. Оборудование и техника — пути. Не производители продуктов, а поставщики машин/деталей.
        // Это случай "statt-shop.de/.../tumbler/tumbler" из исходного примера.
        $equipmentPaths = '/maschinen/|/equipment/|/zubehoer/|/ersatzteile/|/anlagen/|'
            . '/tumbler/|/mischmaschine|/verpackungsmaschine|/fleischwolf|/kutter|'
            . '/technik/|/technologie/|/teile/';

        // 5b. Оборудование — доменные паттерны (например mayer-maschinen.de).
        // Это equipment-производители, нам они не нужны — нужны те, кто делает САМО мясо.
        $equipmentDomains = '-maschinen\.|maschinen-|-anlagen\.|anlagen-|-anlagenbau\.|anlagenbau-|'
            . '-technik\.|technik-|-geraete\.|-systeme\.|-werkzeug\.|werkzeug-|'
            . '-edelstahl\.|edelstahl-|-pack\.|pack-|-verpackung\.|verpackung-|'
            . '-arbeitssicher|arbeitssicher-|asz-gmbh';

        // 6. Документы (PDF/DOC), регуляторы/образование, ассоциации/союзы.
        // PDF-каталог производителя ценен, но мы не парсим PDF — пусть лежит мимо.
        // Verband / Verein — это ассоциации (vdf.de, fleischerverband etc), не производители.
        $docsAndGov = '\.pdf$|\.doc$|\.docx$|\.xls$|\.xlsx$|'
            . 'wko\.at|bmel\.de|\.gov\.|\.gov$|/regierung|'
            . 'uni-|hochschule-|tu-berlin|tu-muenchen|tu-dresden|'
            . '-verband\.|verband-|-verein\.|verein-|v-d-f\.|fleischerverband|industrieverband';

        $pattern = '#(?:'
            . $globalPlatforms . '|'
            . $aggregators . '|'
            . $shopDomains . '|'
            . $shopPaths . '|'
            . $equipmentPaths . '|'
            . $equipmentDomains . '|'
            . $docsAndGov
            . ')#i';

        return (bool) preg_match($pattern, $url);
    }
}
