<?php

namespace App\Jobs;

/**
 * Параметры запуска SearchJob.
 *
 * Используется как неизменяемый value-object: каждое перепланирование
 * (retry той же итерации или переход к следующей странице SERP) создаёт
 * новый экземпляр через with-методы next() / retry(). Сам DTO не мутируется.
 *
 * Зачем нужен: точка вызова SearchJob::dispatch встречается в трёх местах
 * (QueryController, retry в handle, scheduleNextIteration). Без DTO в каждом
 * месте перечислялся бы одинаковый список из 8 позиционных аргументов —
 * любая правка сигнатуры требует синхронных изменений и легко ломается
 * из-за порядка nullable-полей.
 */
final class SearchJobInput
{
    public function __construct(
        public readonly int $searchQueryId,
        public readonly string $textQuery,
        public readonly ?string $languageCode = null,
        public readonly ?int $typeBusinessId = null,
        public readonly ?int $languageId = null,
        public readonly int $iteration = 0,
        public readonly ?array $nextPageParams = null,
    ) {}

    /**
     * Новый экземпляр для следующей итерации обхода SERP.
     *
     * iteration увеличивается на 1, на вход — свежие hidden-поля формы Next
     * с предыдущей страницы. Счётчик retry больше не передаётся: Laravel
     * сам управляет попытками через $tries / backoff() / failed().
     */
    public function next(array $nextPageParams): self
    {
        return new self(
            $this->searchQueryId,
            $this->textQuery,
            $this->languageCode,
            $this->typeBusinessId,
            $this->languageId,
            $this->iteration + 1,
            $nextPageParams,
        );
    }
}
