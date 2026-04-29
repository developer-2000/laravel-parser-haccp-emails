<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShowQueryRequest;
use App\Jobs\SearchJob;
use App\Models\SearchQuery;
use App\Services\AppLogger;
use Illuminate\Http\JsonResponse;

class QueryController extends BaseController
{
    /**
     * Запускает обход поисковой выдачи для выбранного поискового запроса.
     *
     * Берёт SearchQuery по id (валидирован в ShowQueryRequest),
     * подтягивает связанный язык и диспатчит SearchJob с параметрами:
     *   - id запроса (для трассировки)
     *   - текст запроса (что искать)
     *   - код языка (de / en / …) — для DDG kl и Accept-Language
     *   - id типа бизнеса — выбор набора правил в LeadClassifier
     */
    public function makeQuery(ShowQueryRequest $request): JsonResponse
    {
        $searchQueryId = $request->integer('search_query_id');

        $logger = app(AppLogger::class);
        $logger->clear('search.log');
        $logger->clear('crawl.log');

        $query = SearchQuery::with('language')->findOrFail($searchQueryId);
        $languageCode    = $query->language?->code;
        $typeBusinessId  = $query->type_business_id;

        SearchJob::dispatch($searchQueryId, $query->text, $languageCode, $typeBusinessId)
            ->onQueue('search');

        return $this->getSuccessResponse('', ['query' => $query->text]);
    }
}
