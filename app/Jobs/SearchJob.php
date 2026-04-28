<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\AppLogger;
use App\Services\PlaywrightService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $querySetId;
    public string $query;

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
        int $querySetId,
        string $query,
        int $iteration = 0,
        int $retry = 0,
        ?array $nextPageParams = null
    ) {
        $this->querySetId = $querySetId;
        $this->query = $query;
        $this->iteration = $iteration;
        $this->maxIterations = 3;
        $this->retry = $retry;
        $this->nextPageParams = $nextPageParams;
    }

    public function handle(
        AppLogger $logger,
        PlaywrightService $playwright
    ): void {

        $logger->write("ITERATION = {$this->iteration} retry={$this->retry}", 'search.log');

        if ($this->iteration >= $this->maxIterations) {
            $logger->write("[STOP] maxIterations reached", 'search.log');
            return;
        }

        try {
            sleep(rand(1, 2));

            // Первая итерация — bare search. Последующие — POST формы Next из предыдущей страницы.
            if ($this->iteration === 0 || $this->nextPageParams === null) {
                $logger->write("[req] mode=search (first page)", 'search.log');
                $html = $playwright->search($this->query);
            } else {
                $sParam = $this->nextPageParams['s'] ?? '?';
                $logger->write("[req] mode=nextPage s={$sParam}", 'search.log');
                $html = $playwright->nextPage($this->nextPageParams);
            }

            $bytes = strlen($html);
            $logger->write("[2] bytes={$bytes}", 'search.log');

            file_put_contents(
                storage_path("logs/ddg-iteration-{$this->iteration}.html"),
                $html
            );

            if ($bytes < 5000 || str_contains($html, 'captcha')) {
                throw new \Exception('BLOCK_OR_EMPTY');
            }

            // Извлечение и фильтрация ссылок с подробным логом каждого шага.
            $newLinks = $this->extractAndDedupe($html, $logger);

            if (count($newLinks) === 0) {
                $logger->write("[STOP] no new links after dedup", 'search.log');
                return;
            }

            $dispatched = 0;
            foreach ($newLinks as $link) {
//                $logger->write('[v link] ' . $link, 'search.log');

                // В БД не пишем — это делает CrawlJob после off-topic фильтра.
                // Dedup между итерациями частично работает через Company::whereIn выше
                // (по уже сохранённым CrawlJob'ом записям). Возможные дубли диспатча
                // безопасны: Company::updateOrCreate в CrawlJob идемпотентна по unique-индексу url.
                CrawlJob::dispatch($link)->onQueue('crawl');
                $dispatched++;
            }

            $logger->write("[3] dispatched={$dispatched}", 'search.log');

            // Извлекаем форму Next из текущей страницы для следующей итерации.
            $nextPageParams = $this->extractNextPageParams($html);

            if ($nextPageParams === null) {
                $logger->write("[next-page] not found — последняя страница выдачи, STOP", 'search.log');
                return;
            }

            $logger->write(
                "[next-page] found s={$nextPageParams['s']} keys=" . implode(',', array_keys($nextPageParams)),
                'search.log'
            );

            self::dispatch(
                $this->querySetId,
                $this->query,
                $this->iteration + 1,
                0,
                $nextPageParams
            )
                ->delay(now()->addSeconds(rand(2, 5)))
                ->onQueue('search');

        } catch (\Throwable $e) {
            $logger->write("[ERROR] {$e->getMessage()} retry={$this->retry}", 'search.log');

            if ($this->retry < 3) {
                self::dispatch(
                    $this->querySetId,
                    $this->query,
                    $this->iteration,
                    $this->retry + 1,
                    $this->nextPageParams
                )
                    ->delay(now()->addSeconds(rand(3, 8)))
                    ->onQueue('search');

                return;
            }

            $logger->write("[FAIL] iteration={$this->iteration} retries exhausted", 'search.log');
        }
    }

    /**
     * Извлечение ссылок + 3-уровневая дедупликация с логами на каждом шаге.
     */
    private function extractAndDedupe(string $html, AppLogger $logger): array
    {
        $rawLinks = $this->extractLinksSERP($html);
        $logger->write('[raw] count=' . count($rawLinks), 'search.log');

        if (empty($rawLinks)) {
            return [];
        }

        // 1. дедуп within batch (на странице DDG может вернуть один URL дважды).
        $unique = array_values(array_unique($rawLinks));
//        $logger->write('[unique-in-batch] count=' . count($unique) . ' duplicates=' . (count($rawLinks) - count($unique)), 'search.log');

        // 2. фильтр trash-URL (домены магазинов, пути оборудования, агрегаторы, документы).
        $afterTrash = [];
        $trashed = [];
        foreach ($unique as $link) {
            if ($this->isTrashUrl($link)) {
                $trashed[] = $link;
                continue;
            }
            $afterTrash[] = $link;
        }
        if (count($trashed) > 0) {
            $logger->write('[trashed] count=' . count($trashed) . ' list=' . json_encode($trashed, JSON_UNESCAPED_SLASHES), 'search.log');
        }
        $logger->write('[after-trash] count=' . count($afterTrash), 'search.log');

        // 3. batch-дедуп с БД (один запрос на все, не N).
        $existingInDb = Company::whereIn('url', $afterTrash)->pluck('url')->toArray();

        $newLinks = [];
        $dbDuplicates = [];
        foreach ($afterTrash as $link) {
            if (in_array($link, $existingInDb, true)) {
                $dbDuplicates[] = $link;
                continue;
            }
            $newLinks[] = $link;
        }
        if (count($dbDuplicates) > 0) {
            $logger->write('[db-duplicates] count=' . count($dbDuplicates) . ' list=' . json_encode($dbDuplicates, JSON_UNESCAPED_SLASHES), 'search.log');
        }
        $logger->write('[new-unique] count=' . count($newLinks), 'search.log');

        return $newLinks;
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
