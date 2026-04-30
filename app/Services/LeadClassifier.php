<?php

namespace App\Services;

/**
 * Классификация компании по ICP — определяет, насколько сайт похож
 * на нужный нам тип бизнеса (Meat / Milk / …).
 *
 * Все правила scoring'а лежат в config/site/business.php
 * под ключом business_ids.{type_business_id}, в формате:
 *
 *   url => [
 *     positive => [score => [keyword, keyword, ...], ...],
 *     negative => [score => [keyword, keyword, ...], ...],
 *   ],
 *   html => [...] // та же структура
 *
 * Tier mapping:
 *   3 — IDEAL  : чистый профильный производитель (>=6 баллов)
 *   2 — GOOD   : подходящий лид (>=3 баллов)
 *   1 — WEAK   : слабый лид (>=1 балла)
 *   0 — IGNORE : не наш target (мусор, оборудование, ассоциации, логистика)
 *
 * Использование:
 *   - В SearchJob:  classify($url, null, $typeBusinessId) — ранний URL-фильтр.
 *   - В CrawlJob:   classify($url, $html, $typeBusinessId) — финальная с HTML.
 */
class LeadClassifier
{
    /**
     * Минимальный tier для прохода раннего URL-фильтра в SearchJob.
     *
     * SearchJob.filterByClassifier дропает URL с tier < этого значения —
     * заведомо нерелевантные домены не получают CrawlJob.
     */
    public const MIN_TIER_FOR_URL_FILTER = 1;

    /**
     * Минимальный tier для записи компании в БД из CrawlJob.
     *
     * CrawlJob дропает сайт с tier < этого значения. Цель: убирать
     * D2C-бренды, розничных мясников и компании без сильных ICP-сигналов.
     */
    public const MIN_TIER_FOR_CRAWL = 2;

    /**
     * Минимальный score для каждого tier (включительно).
     *
     * Порядок важен: проходим сверху вниз и возвращаем первый совпавший tier.
     * Эмпирически подобранные значения:
     *   - 6+ → IDEAL: множество сильных сигналов в URL и HTML.
     *   - 3+ → GOOD:  достаточно сигналов, чтобы доверять классификации.
     *   - 1+ → WEAK:  хотя бы один сигнал — всё ещё в outreach-выборку,
     *                 но в CrawlJob не сохраняется (порог >= 2).
     *   - <1 → IGNORE (default 0).
     */
    private const TIER_THRESHOLDS = [
        3 => 6,
        2 => 3,
        1 => 1,
    ];

    /**
     * Memoize-кеш правил скоринга по typeBusinessId.
     *
     * SearchJob::filterByClassifier зовёт classify() в цикле для каждого URL
     * с одним и тем же $typeBusinessId — без кеша на каждый вызов идёт
     * dot-notation lookup в config(). В рамках одного экземпляра сервиса
     * первый вызов резолвит правила из конфига, последующие — берут из массива.
     *
     * @var array<int, array|null>
     */
    private array $rulesCache = [];

    /**
     * Классифицировать URL (и опционально HTML страницы) для заданного типа бизнеса.
     *
     * @param string      $url             URL компании
     * @param string|null $html            HTML главной (если есть) — для текстовых сигналов
     * @param int|null    $typeBusinessId  id типа бизнеса; если null или нет правил → score=0
     * @return int Tier 0..3
     */
    public function classify(string $url, ?string $html = null, ?int $typeBusinessId = null): int
    {
        if ($typeBusinessId === null) {
            return 0;
        }

        $rules = $this->resolveRules($typeBusinessId);

        if (!is_array($rules)) {
            // правил для этого типа бизнеса нет — нечего классифицировать
            return 0;
        }

        $score = $this->scoreText(strtolower($url), $rules['url'] ?? []);

        if ($html !== null) {
            $text = mb_strtolower(strip_tags($html));
            $score += $this->scoreText($text, $rules['html'] ?? []);
        }

        return $this->mapScoreToTier($score);
    }

    /**
     * Подробный разбор скоринга — возвращает score и списки совпавших keywords.
     *
     * Используется для отладочного логирования: видеть, почему конкретный URL
     * получил tier=0 и какие именно правила сработали в плюс/минус.
     *
     * @return array{score:int, positive:array<string,int>, negative:array<string,int>, tier:int}
     *         positive/negative — карта keyword → weight (с положительным знаком).
     */
    public function explain(string $url, ?string $html = null, ?int $typeBusinessId = null): array
    {
        $empty = ['score' => 0, 'positive' => [], 'negative' => [], 'tier' => 0];

        if ($typeBusinessId === null) {
            return $empty;
        }
        $rules = $this->resolveRules($typeBusinessId);
        if (!is_array($rules)) {
            return $empty;
        }

        [$urlScore, $urlPos, $urlNeg] = $this->scoreTextDetailed(strtolower($url), $rules['url'] ?? []);

        $htmlScore = 0;
        $htmlPos = [];
        $htmlNeg = [];
        if ($html !== null) {
            [$htmlScore, $htmlPos, $htmlNeg] = $this->scoreTextDetailed(mb_strtolower(strip_tags($html)), $rules['html'] ?? []);
        }

        $score = $urlScore + $htmlScore;

        return [
            'score'    => $score,
            'positive' => $urlPos + $htmlPos,
            'negative' => $urlNeg + $htmlNeg,
            'tier'     => $this->mapScoreToTier($score),
        ];
    }

    /**
     * Достать правила скоринга для typeBusinessId — с memoize-кешем.
     *
     * При первом вызове в рамках инстанса резолвит config(...) и кладёт
     * результат (массив или null) в $rulesCache. Последующие вызовы с тем
     * же typeBusinessId берут значение из кеша, без dot-notation lookup.
     *
     * Возвращает массив правил или null, если для типа бизнеса конфига нет.
     */
    private function resolveRules(int $typeBusinessId): ?array
    {
        if (!array_key_exists($typeBusinessId, $this->rulesCache)) {
            $this->rulesCache[$typeBusinessId] = config("site.business.business_ids.{$typeBusinessId}");
        }

        return $this->rulesCache[$typeBusinessId];
    }

    /**
     * Универсальный подсчёт score по тексту (URL или HTML) на основе блока правил.
     *
     * Структура $rules:
     *   [
     *     'positive' => [score => [keyword, ...], ...],  // +score за каждое попадание
     *     'negative' => [score => [keyword, ...], ...],  // -score за каждое попадание
     *   ]
     */
    private function scoreText(string $text, array $rules): int
    {
        $score = 0;

        foreach ($rules['positive'] ?? [] as $weight => $keywords) {
            foreach ((array) $keywords as $kw) {
                if ($kw !== '' && str_contains($text, $kw)) {
                    $score += (int) $weight;
                }
            }
        }

        foreach ($rules['negative'] ?? [] as $weight => $keywords) {
            foreach ((array) $keywords as $kw) {
                if ($kw !== '' && str_contains($text, $kw)) {
                    $score -= (int) $weight;
                }
            }
        }

        return $score;
    }

    /**
     * Детализированный score: возвращает не только итог, но и карту совпавших keywords.
     *
     * @return array{0:int, 1:array<string,int>, 2:array<string,int>}
     *         [score, positive_matches, negative_matches] — вторые два массива
     *         в формате keyword => weight (положительный, без знака).
     */
    private function scoreTextDetailed(string $text, array $rules): array
    {
        $score = 0;
        $positive = [];
        $negative = [];

        foreach ($rules['positive'] ?? [] as $weight => $keywords) {
            foreach ((array) $keywords as $kw) {
                if ($kw !== '' && str_contains($text, $kw)) {
                    $score += (int) $weight;
                    $positive[$kw] = (int) $weight;
                }
            }
        }

        foreach ($rules['negative'] ?? [] as $weight => $keywords) {
            foreach ((array) $keywords as $kw) {
                if ($kw !== '' && str_contains($text, $kw)) {
                    $score -= (int) $weight;
                    $negative[$kw] = (int) $weight;
                }
            }
        }

        return [$score, $positive, $negative];
    }

    /**
     * Прошёл ли tier ранний URL-фильтр в SearchJob.
     *
     * Используется в filterByClassifier — отсекаем заведомо нерелевантные URL
     * до отправки CrawlJob в очередь.
     */
    public function passesUrlFilter(int $tier): bool
    {
        return $tier >= self::MIN_TIER_FOR_URL_FILTER;
    }

    /**
     * Прошёл ли tier финальный crawl-порог в CrawlJob.
     *
     * Используется в handle — компания с tier ниже этого значения не
     * сохраняется в БД (низкокачественный лид).
     */
    public function passesCrawl(int $tier): bool
    {
        return $tier >= self::MIN_TIER_FOR_CRAWL;
    }

    /**
     * Сворачиваем сырой score в дискретный tier 0..3.
     *
     * Идём по TIER_THRESHOLDS сверху вниз и возвращаем первый tier,
     * для которого набран минимальный score. Если ни один порог не достигнут — 0.
     */
    private function mapScoreToTier(int $score): int
    {
        foreach (self::TIER_THRESHOLDS as $tier => $minScore) {
            if ($score >= $minScore) {
                return $tier;
            }
        }

        return 0;
    }
}
