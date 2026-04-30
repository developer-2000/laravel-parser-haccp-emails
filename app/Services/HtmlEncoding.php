<?php

namespace App\Services;

/**
 * Приведение HTML к валидному UTF-8.
 *
 * Зачем: старые .de-сайты часто отдают ISO-8859-1 / Windows-1252; сырые байты
 * вроде `\xF6` (ö) ломают INSERT в MySQL utf8mb4 и роняют CrawlJob. Сервис
 * детектирует исходную кодировку по нескольким сигналам и приводит body
 * к чистому UTF-8 с финальной страховкой от невалидных байтов.
 */
class HtmlEncoding
{
    /**
     * Привести HTML к валидному UTF-8.
     *
     * Алгоритм:
     *   1. <meta charset=...> → декларированная кодировка (если non-UTF-8).
     *   2. Авто-детект через mb_detect_encoding, если декларации нет
     *      и body уже не валидный UTF-8.
     *   3. Финальная страховка — replace невалидных байтов через mb_convert_encoding.
     */
    public function toUtf8(string $body): string
    {
        $encoding = $this->detectFromMeta($body) ?? $this->detectByContent($body);

        if ($encoding !== null && $encoding !== 'UTF-8') {
            $body = mb_convert_encoding($body, 'UTF-8', $encoding);
        }

        return $this->ensureValidUtf8($body);
    }

    /**
     * Считать декларацию из <meta charset=...> или <meta http-equiv=...; charset=...>.
     *
     * Возвращает каноническое имя кодировки только если она входит в наш
     * whitelist non-UTF-8 (ISO-8859-1 / Windows-1252 / ISO-8859-15) — для
     * остальных случаев возвращает null, и дальше работает auto-detect.
     */
    private function detectFromMeta(string $body): ?string
    {
        if (!preg_match('/<meta[^>]*charset=["\']?([\w-]+)/i', $body, $m)) {
            return null;
        }

        $declared = strtoupper(trim($m[1]));
        $declared = match ($declared) {
            'LATIN1', 'LATIN-1' => 'ISO-8859-1',
            'CP1252'            => 'WINDOWS-1252',
            default             => $declared,
        };

        return in_array($declared, ['ISO-8859-1', 'WINDOWS-1252', 'ISO-8859-15'], true)
            ? $declared
            : null;
    }

    /**
     * Авто-детект кодировки по содержимому, если body не валидный UTF-8.
     *
     * Перебирает кандидатов в порядке приоритета. Если детект не сработал —
     * fallback на ISO-8859-1 (самая распространённая для DACH-сайтов без meta).
     */
    private function detectByContent(string $body): ?string
    {
        if (mb_check_encoding($body, 'UTF-8')) {
            return null;
        }

        return mb_detect_encoding(
            $body,
            ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ISO-8859-15'],
            true
        ) ?: 'ISO-8859-1';
    }

    /**
     * Финальная страховка — после всех преобразований гарантирует валидный UTF-8.
     *
     * Если в body всё ещё остались битые байты — `mb_convert_encoding($body, 'UTF-8', 'UTF-8')`
     * заменяет их на substitute character (Laravel/PHP-default), не ломая INSERT в БД.
     */
    private function ensureValidUtf8(string $body): string
    {
        if (mb_check_encoding($body, 'UTF-8')) {
            return $body;
        }

        return mb_convert_encoding($body, 'UTF-8', 'UTF-8');
    }
}
