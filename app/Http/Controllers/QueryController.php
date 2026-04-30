<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShowQueryRequest;
use App\Jobs\SearchJob;
use App\Jobs\SearchJobInput;
use App\Models\ParsingState;
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
        $languageId     = $query->language_id;


        // 1 Получить или создать запись ParsingState для пары (lang + type).
        //    Семантика ключа — пара (language_id, type_business_id), а не
        //    search_query_id: разные SearchQuery с одной парой рассматриваются
        //    как разные формулировки одного и того же продуктового парсинга.
        //    firstOrCreate гарантирует, что запись точно есть в БД — никаких
        //    "unsaved модель и непонятно что в полях".
        $state = ParsingState::firstOrCreate(
            [
                'language_id'      => $languageId,
                'type_business_id' => $typeBusinessId,
            ],
            [
                'completion_status' => 0,
                'next_page_params'  => null,
            ]
        );

        if ($state->completion_status === 1) {
            return $this->getSuccessResponse('Этот парсинг завершен');
        }

        // 2 Поставить в очередь обработку этого запроса.
        //    Стартуем с iteration=0; режим определяется наличием nextPageParams:
        //    если null — bare search (s=0), если есть cursor — resume с этой страницы.
        //    Парсер обходит SERP до конца (markCompleted при null-next-page).
        $input = new SearchJobInput(
            searchQueryId:   $searchQueryId,
            textQuery:       $query->text,
            languageCode:    $languageCode,
            typeBusinessId:  $typeBusinessId,
            languageId:      $languageId,
            iteration:       0,
            nextPageParams:  $state->next_page_params,
        );

        SearchJob::dispatch($input)->onQueue('search');

        return $this->getSuccessResponse('', ['query' => $query->text]);
    }
}
