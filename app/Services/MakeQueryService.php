<?php

namespace App\Services;

use App\Jobs\SearchJob;
use App\Jobs\SearchJobInput;
use App\Models\SearchQuery;

/**
 * Запускает парсинг для выбранного SearchQuery: чистит логи прошлого
 * прогона и ставит SearchJob в очередь search.
 *
 * Вызывается из QueryController::makeQuery. Сам контроллер занимается
 * только HTTP-обвязкой (request → service → JsonResponse), бизнес-логика
 * целиком здесь.
 */
class MakeQueryService
{
    public function __construct(
        private AppLogger $logger
    ) {
    }

    /**
     * Выполнить запуск:
     *   1. clear search.log / crawl.log (чтобы новый прогон не смешивался со старым);
     *   2. подтянуть SearchQuery с языком;
     *   3. собрать SearchJobInput и поставить SearchJob в очередь search.
     *
     * Возвращает текст запроса для UX-ответа клиенту.
     */
    public function execute(int $searchQueryId): string
    {
        $this->logger->clear('search.log');
        $this->logger->clear('crawl.log');

        $query = SearchQuery::with('language')->findOrFail($searchQueryId);

        SearchJob::dispatch(new SearchJobInput(
            searchQueryId:  $searchQueryId,
            textQuery:      $query->text,
            languageCode:   $query->language?->code,
            typeBusinessId: $query->type_business_id,
        ))->onQueue('search');

        return $query->text;
    }
}
