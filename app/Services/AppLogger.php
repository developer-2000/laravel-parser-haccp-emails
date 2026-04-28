<?php

namespace App\Services;

use App\Models\AppLog;
use Illuminate\Support\Facades\File;

class AppLogger
{
    public const TYPE_FILE = 'file';
    public const TYPE_DATABASE = 'database';
    public const DEFAULT_FILENAME = 'app.log';

    /**
     * Записать лог.
     *
     * @param string|array $message Сообщение (если массив — приводится к JSON)
     * @param string $filename Имя файла (для type=file), например "custom.log"
     * @param string $type 'file' | 'database'
     * @param array|null $context Доп. данные (для database попадут в поле context json)
     * @param bool $reset Если true — сначала очищает файл, потом пишет (для type=file)
     * @return bool
     */
    public function write(
        string|array $message = '',
        string $filename = '',
        string $type = self::TYPE_FILE,
        ?array $context = null,
        bool $reset = false
    ): bool
    {
        if (is_array($message)) {
            $message = json_encode($message, JSON_UNESCAPED_UNICODE);
        }

        // match: сравнивает $type с вариантами, выполняет ветку и возвращает её результат.
        return match ($type) {
            self::TYPE_DATABASE => $this->writeToDatabase($message, $context),
            default => $this->writeToFile($message, $filename ?: self::DEFAULT_FILENAME, $reset),
        };
    }

    /**
     * Очистить файл лога. Если файла нет — создаст пустой.
     *
     * @param string $filename Имя файла (например "custom.log")
     * @return bool Успех операции
     */
    public function clear(string $filename): bool
    {
        $dir = storage_path('logs');
        if (!File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }
        $path = $dir . '/' . (str_ends_with($filename, '.log') ? $filename : $filename . '.log');

        return file_put_contents($path, '', LOCK_EX) !== false;
    }

    /**
     * Записать сообщение в файл в storage/logs. Создаёт директорию и файл при отсутствии.
     *
     * @param string $message Сообщение
     * @param string $filename Имя файла (например "custom.log")
     * @param bool $reset Если true — обнулить файл перед записью (вместо append)
     * @return bool Успех записи
     */
    protected function writeToFile(string $message, string $filename, bool $reset = false): bool
    {
        $dir = storage_path('logs');
        if (!File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }
        $path = $dir . '/' . (str_ends_with($filename, '.log') ? $filename : $filename . '.log');
        $line = '[' . now()->toDateTimeString() . '] ' . $message . "\n";

        $flags = $reset ? LOCK_EX : (FILE_APPEND | LOCK_EX);

        return file_put_contents($path, $line, $flags) !== false;
    }

    /**
     * Записать сообщение в таблицу app_logs. message обрезается до 255 символов, context — в json.
     *
     * @param string $message Сообщение (до 255 символов)
     * @param array|null $context Доп. данные (сохраняются в поле context)
     * @return bool Успех записи
     */
    protected function writeToDatabase(string $message, ?array $context = null): bool
    {
        $message = mb_substr($message, 0, 255);
        try {
            AppLog::query()->create([
                'message' => $message,
                'context' => $context,
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
