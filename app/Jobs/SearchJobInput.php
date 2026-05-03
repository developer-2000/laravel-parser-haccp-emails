<?php

namespace App\Jobs;

/**
 * Параметры запуска SearchJob — неизменяемый value-object.
 *
 * Зачем нужен: точка вызова SearchJob::dispatch принимает 4 аргумента,
 * без DTO они шли бы позиционно — любая правка сигнатуры требует синхронных
 * изменений во всех точках вызова. С DTO (named-args) изменения локальные.
 */
final class SearchJobInput
{
    public function __construct(
        public readonly int $searchQueryId,
        public readonly string $textQuery,
        public readonly ?string $languageCode = null,
        public readonly ?int $typeBusinessId = null,
    ) {
    }
}
