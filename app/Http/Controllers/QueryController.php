<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShowQueryRequest;
use App\Jobs\SearchJob;
use App\Models\Company;
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


        // 1 Проверка что этот парсинг ещё не завершён
        $state = ParsingState::firstOrNew([
            'language_id' => $languageId,
            'type_business_id' => $typeBusinessId,
        ]);

        if ($state->exists && $state->completion_status === 1) {
            return $this->getSuccessResponse('Этот парсинг завершен');
        }

        // 2 Зафиксировать начало парсинга этого сета.
        //    next_page_params НЕ затираем — если он есть, это указатель на страницу,
        //    с которой надо возобновить прерванный прогон.
        $state->completion_status = 0;
        $state->save();

        // 3 Поставить в очередь обработку этого запроса.
        //    Если есть сохранённые параметры пагинации — стартуем с iteration=1
        //    и этими параметрами (resume), иначе обычный bare search с нуля.
        $resumeParams = $state->next_page_params;
        $iteration    = $resumeParams ? 1 : 0;

        SearchJob::dispatch(
            $searchQueryId,
            $query->text,
            $languageCode,
            $typeBusinessId,
            $languageId,
            $iteration,
            0,
            $resumeParams
        )->onQueue('search');

        return $this->getSuccessResponse('', ['query' => $query->text]);
    }
}
