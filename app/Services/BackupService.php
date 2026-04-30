<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class BackupService
{
    /**
     * Если на сегодня бэкапа ещё нет — создать его, иначе ничего не делать.
     *
     * Файл лежит в storage/app/backups/backup_YYYY-MM-DD.sql.
     * Идемпотентность по дате: повторные вызовы в течение суток —
     * no-op (вернут reason='already_exists').
     *
     * @return array{done:bool,file:?string,reason:string}
     */
    public function dailyBackupIfNeeded(): array
    {
        $dir  = storage_path('app/backups');
        $file = $dir . DIRECTORY_SEPARATOR . 'backup_' . date('Y-m-d') . '.sql';

        if (File::exists($file)) {
            return ['done' => false, 'file' => $file, 'reason' => 'already_exists'];
        }

        if (!File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $this->dump($file);

        return ['done' => true, 'file' => $file, 'reason' => 'created'];
    }

    /**
     * Сериализует все таблицы текущего соединения в SQL-файл.
     *
     * Формат: для каждой таблицы — DROP TABLE IF EXISTS, затем CREATE TABLE
     * (через SHOW CREATE TABLE — точная схема включая индексы и FK), затем
     * INSERT INTO ... VALUES (...) пачками по 200 строк (компромисс между
     * размером запроса и числом INSERT'ов).
     *
     * Запись построчно через fwrite — не держим весь дамп в памяти.
     */
    private function dump(string $file): void
    {
        $handle = fopen($file, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Не удалось открыть файл бэкапа на запись: {$file}");
        }

        try {
            fwrite($handle, "-- Backup created at " . date('Y-m-d H:i:s') . "\n");
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
            fwrite($handle, "SET NAMES utf8mb4;\n\n");

            $pdo = DB::connection()->getPdo();

            $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                $this->dumpTable($pdo, $handle, $table);
            }

            fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($handle);
        }
    }

    /**
     * Дампит структуру одной таблицы (DROP + CREATE) и её данные пачками INSERT.
     */
    private function dumpTable(\PDO $pdo, $handle, string $table): void
    {
        $tableQuoted = '`' . str_replace('`', '``', $table) . '`';

        fwrite($handle, "\n-- ----------------------------\n");
        fwrite($handle, "-- Table: {$table}\n");
        fwrite($handle, "-- ----------------------------\n");
        fwrite($handle, "DROP TABLE IF EXISTS {$tableQuoted};\n");

        $createRow = $pdo->query("SHOW CREATE TABLE {$tableQuoted}")->fetch(\PDO::FETCH_ASSOC);
        $createSql = $createRow['Create Table'] ?? '';
        if ($createSql === '') {
            return;
        }
        fwrite($handle, $createSql . ";\n\n");

        $rowCount = (int) $pdo->query("SELECT COUNT(*) FROM {$tableQuoted}")->fetchColumn();
        if ($rowCount === 0) {
            return;
        }

        // Тащим строки порциями по 1000 (через unbuffered statement) и пишем
        // INSERT'ами по 200 значений — баланс между размером SQL-запроса и
        // количеством отдельных операторов.
        $stmt = $pdo->query("SELECT * FROM {$tableQuoted}", \PDO::FETCH_ASSOC);

        $batch = [];
        $columnsList = null;

        foreach ($stmt as $row) {
            if ($columnsList === null) {
                $columnsList = '(' . implode(', ', array_map(
                    fn ($c) => '`' . str_replace('`', '``', $c) . '`',
                    array_keys($row)
                )) . ')';
            }

            $values = array_map(fn ($v) => $this->quoteValue($pdo, $v), $row);
            $batch[] = '(' . implode(', ', $values) . ')';

            if (count($batch) >= 200) {
                $this->flushBatch($handle, $tableQuoted, $columnsList, $batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $this->flushBatch($handle, $tableQuoted, $columnsList, $batch);
        }
    }

    /**
     * Записывает накопленный батч строк одним INSERT'ом.
     */
    private function flushBatch($handle, string $tableQuoted, string $columnsList, array $batch): void
    {
        fwrite($handle, "INSERT INTO {$tableQuoted} {$columnsList} VALUES\n");
        fwrite($handle, implode(",\n", $batch));
        fwrite($handle, ";\n\n");
    }

    /**
     * Безопасное экранирование значения для SQL.
     *
     * NULL → NULL, остальное — через PDO::quote (одинарные кавычки + escaping).
     * bool → 0/1, потому что MySQL хранит как TINYINT(1).
     */
    private function quoteValue(\PDO $pdo, $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $pdo->quote((string) $value);
    }
}
