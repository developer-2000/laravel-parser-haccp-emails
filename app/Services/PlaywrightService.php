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
     *
     * languageCode (de / en / fr / …) резолвится в kl и accept_language через
     * config/site/language.php. Если не передан или не найден — берём дефолт.
     */
    public function search(string $query, ?string $languageCode = null): string
    {
        $cfg = $this->resolveLanguageConfig($languageCode);

        return $this->client->post(
            self::URL,
            ['q' => $query, 'kl' => $cfg['kl']],
            $cfg['accept_language']
        );
    }

    /**
     * Последующие запросы — POST готовых hidden-полей формы Next из предыдущей страницы.
     * DDG требует именно этих параметров (q, s, dc, vqd, nextParams ...) для реальной пагинации.
     *
     * Accept-Language всё равно подставляем по выбранному языку — DDG смотрит на него
     * и при пагинации тоже, чтобы выдача не сбрасывалась на дефолт.
     */
    public function nextPage(array $formParams, ?string $languageCode = null): string
    {
        $cfg = $this->resolveLanguageConfig($languageCode);

        return $this->client->post(self::URL, $formParams, $cfg['accept_language']);
    }

    /**
     * Резолвит код языка в массив { kl, accept_language }.
     *
     * Если код не передан или его нет в списке — возвращает дефолт (по
     * config('site.language.default') или, в крайнем случае, en/us-en).
     */
    private function resolveLanguageConfig(?string $code): array
    {
        $list = config('site.language.languages', []);
        $code = $code ?: config('site.language.default', 'en');

        foreach ($list as $lang) {
            if (($lang['code'] ?? null) === $code) {
                return [
                    'kl'              => $lang['kl']              ?? 'us-en',
                    'accept_language' => $lang['accept_language'] ?? 'en-US,en;q=0.9',
                ];
            }
        }

        return ['kl' => 'us-en', 'accept_language' => 'en-US,en;q=0.9'];
    }
}
