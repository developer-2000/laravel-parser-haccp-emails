<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class DuckHttpClient
{
    public function get(string $url): string
    {
        return Http::timeout(20)
            ->withHeaders($this->headers())
            ->get($url)
            ->body();
    }

    public function post(string $url, array $data): string
    {
        return Http::timeout(20)
            ->asForm()
            ->withHeaders($this->postHeaders())
            ->post($url, $data)
            ->body();
    }

    private function headers(): array
    {
        return [
            'User-Agent' => $this->ua(),
            'Accept' => 'text/html',
            'Accept-Language' => 'de-DE,de;q=0.9',
        ];
    }

    private function postHeaders(): array
    {
        return [
            'User-Agent' => $this->ua(),
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Origin' => 'https://duckduckgo.com',
            'Referer' => 'https://duckduckgo.com/',
            'Accept-Language' => 'de-DE,de;q=0.9',
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
