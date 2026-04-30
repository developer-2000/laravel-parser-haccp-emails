<?php

namespace App\Services;

/**
 * Парсер HTML страницы выдачи DuckDuckGo (/html/-эндпоинт).
 *
 * Изолирует знание о разметке DDG в одном месте: при смене HTML-структуры
 * DDG (или anomaly-странице) ломаться будет только этот класс, а не Job.
 * Stateless — безопасно резолвится через Laravel-контейнер.
 */
class SerpParser
{
    /**
     * Извлечь ссылки на сайты из HTML-выдачи DuckDuckGo.
     *
     * Каждая результат-ссылка обёрнута в <a class="result__a" href="..."> и часто
     * проксирована через uddg-redirect. Метод декодирует uddg в реальный URL,
     * срезает query-string (strtok по '?') и возвращает плоский список.
     *
     * @return string[] Сырые URL без дедупликации и фильтрации (это делает SearchJob).
     */
    public function extractLinks(string $html): array
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
     * Извлечь hidden-поля формы перехода на СЛЕДУЮЩУЮ страницу выдачи DDG.
     *
     * DDG /html/ оборачивает кнопки навигации в <div class="nav-link"> с form внутри.
     * На промежуточной странице обычно ДВЕ формы: "< Previous" и "Next >".
     * На последней странице остаётся только "< Previous" — раньше парсер слепо
     * брал последнюю секцию и из-за этого зацикливался s=40 → s=55 → s=40.
     *
     * Сейчас явно ищем секцию, в которой submit-кнопка содержит "Next" — только
     * её inputs идут в ответ. Если такой секции нет — возвращаем null (это конец
     * выдачи).
     *
     * @return array<string,string>|null Полный набор hidden-полей формы Next.
     */
    public function extractNextPageParams(string $html): ?array
    {
        if (!preg_match_all('/<div\s+class="nav-link"[^>]*>(.*?)<\/div>/s', $html, $blocks)) {
            return null;
        }

        $nextSection = $this->findNextSection($blocks[1]);
        if ($nextSection === null) {
            return null;
        }

        preg_match_all(
            '/<input[^>]*name="([^"]+)"[^>]*value="([^"]*)"/i',
            $nextSection,
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

        if (!isset($params['q']) || !isset($params['s'])) {
            return null;
        }

        return $params;
    }

    /**
     * Среди nav-link секций найти ту, у которой submit-кнопка означает "Next".
     *
     * DDG помечает кнопку value="Next" (с возможным "&gt;" / "›" рядом).
     * Возвращаем содержимое секции или null, если ни одна не подошла —
     * значит на этой странице есть только Previous (последняя страница выдачи).
     */
    private function findNextSection(array $sections): ?string
    {
        foreach ($sections as $section) {
            if (preg_match('/<input[^>]*type="submit"[^>]*value="[^"]*Next[^"]*"/i', $section)) {
                return $section;
            }
        }

        return null;
    }
}
