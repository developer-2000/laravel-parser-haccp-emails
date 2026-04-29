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

        $rules = config("site.business.business_ids.{$typeBusinessId}");

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
     * Сворачиваем сырой score в дискретный tier 0..3.
     */
    private function mapScoreToTier(int $score): int
    {
        return match (true) {
            $score >= 6 => 3,
            $score >= 3 => 2,
            $score >= 1 => 1,
            default     => 0,
        };
    }
}
