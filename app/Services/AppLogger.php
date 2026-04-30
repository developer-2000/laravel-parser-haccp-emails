<?php

namespace App\Services;

use App\Models\AppLog;
use Illuminate\Support\Facades\File;

class AppLogger
{
    public const DEFAULT_FILENAME = 'app.log';

    /**
     * Записать сообщение в файл в storage/logs.
     *
     * @param string|array $message Сообщение (массив автоматически приводится к JSON).
     * @param string       $filename Имя файла (например "search.log"). Пустое — DEFAULT_FILENAME.
     * @param bool         $reset Если true, файл сначала обнуляется (вместо append).
     * @return bool Успех записи.
     */
    public function writeFile(string|array $message, string $filename = '', bool $reset = false): bool
    {
        $path = $this->resolvePath($filename ?: self::DEFAULT_FILENAME);
        $line = '[' . now()->toDateTimeString() . '] ' . $this->stringify($message) . "\n";
        $flags = $reset ? LOCK_EX : (FILE_APPEND | LOCK_EX);

        return file_put_contents($path, $line, $flags) !== false;
    }

    /**
     * Записать сообщение в таблицу app_logs.
     *
     * @param string|array $message Сообщение (массив сериализуется в JSON, потом обрезается до 255 символов).
     * @param array|null   $context Доп. данные, попадают в поле context (json).
     * @return bool Успех записи.
     */
    public function writeDb(string|array $message, ?array $context = null): bool
    {
        try {
            AppLog::query()->create([
                'message' => mb_substr($this->stringify($message), 0, 255),
                'context' => $context,
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Очистить файл лога. Если файла нет — создаст пустой.
     */
    public function clear(string $filename): bool
    {
        return file_put_contents($this->resolvePath($filename), '', LOCK_EX) !== false;
    }

    /**
     * Резолв абсолютного пути к лог-файлу в storage/logs/.
     *
     * Создаёт директорию, если её нет, и достраивает расширение .log,
     * если в filename его не было (logger->writeFile(..., 'crawl') → crawl.log).
     */
    private function resolvePath(string $filename): string
    {
        $dir = storage_path('logs');
        if (!File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        if (!str_ends_with($filename, '.log')) {
            $filename .= '.log';
        }

        return $dir . '/' . $filename;
    }

    /**
     * Привести сообщение к строке: массив → JSON, строка возвращается as-is.
     */
    private function stringify(string|array $message): string
    {
        return is_array($message) ? json_encode($message, JSON_UNESCAPED_UNICODE) : $message;
    }
}
