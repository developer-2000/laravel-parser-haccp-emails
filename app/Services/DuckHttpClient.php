<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class DuckHttpClient
{
    /**
     * Дефолтный Accept-Language, если не передан явно.
     * Используется только как страховка; обычно вызывающий передаёт значение
     * из config/site/language.php (через PlaywrightService).
     */
    private const DEFAULT_ACCEPT_LANGUAGE = 'en-US,en;q=0.9';

    public function get(string $url, ?string $acceptLanguage = null): string
    {
        return Http::timeout(20)
            ->withHeaders($this->headers($acceptLanguage))
            ->get($url)
            ->body();
    }

    public function post(string $url, array $data, ?string $acceptLanguage = null): string
    {
        return Http::timeout(20)
            ->asForm()
            ->withHeaders($this->postHeaders($acceptLanguage))
            ->post($url, $data)
            ->body();
    }

    private function headers(?string $acceptLanguage): array
    {
        return [
            'User-Agent'      => $this->ua(),
            'Accept'          => 'text/html',
            'Accept-Language' => $acceptLanguage ?? self::DEFAULT_ACCEPT_LANGUAGE,
        ];
    }

    private function postHeaders(?string $acceptLanguage): array
    {
        return [
            'User-Agent'      => $this->ua(),
            'Content-Type'    => 'application/x-www-form-urlencoded',
            'Origin'          => 'https://duckduckgo.com',
            'Referer'         => 'https://duckduckgo.com/',
            'Accept-Language' => $acceptLanguage ?? self::DEFAULT_ACCEPT_LANGUAGE,
        ];
    }

    private function ua(): string
    {
        return [
                   'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/124 Safari/537.36',
                   'Mozilla/5.0 (Macintosh; Intel Mac OS X) Chrome/124 Safari/537.36',
               ][array_rand([0,1])];
    }
}
