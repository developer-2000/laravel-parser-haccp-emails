<?php

namespace App\Services;

class PlaywrightService
{
    private const URL = 'https://html.duckduckgo.com/html/';

    public function __construct(
        private DuckHttpClient $client
    ) {}

    /**
     * Первый запрос — только q + kl.
     * DDG отдаст HTML, из которого SearchJob извлечёт форму Next для следующей итерации.
     */
    public function search(string $query): string
    {
        return $this->client->post(self::URL, [
            'q'  => $query,
            'kl' => 'de-de',
        ]);
    }

    /**
     * Последующие запросы — POST готовых hidden-полей формы Next из предыдущей страницы.
     * DDG требует именно этих параметров (q, s, dc, vqd, nextParams ...) для реальной пагинации.
     */
    public function nextPage(array $formParams): string
    {
        return $this->client->post(self::URL, $formParams);
    }
}
