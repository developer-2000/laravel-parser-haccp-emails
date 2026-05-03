<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShowQueryRequest;
use App\Services\MakeQueryService;
use Illuminate\Http\JsonResponse;

class QueryController extends BaseController
{
    /**
     * HTTP-точка входа для запуска обхода поисковой выдачи.
     *
     * Контроллер делает только обвязку:
     *   - валидация (ShowQueryRequest);
     *   - делегирование MakeQueryService::execute (там вся бизнес-логика: dispatch SearchJob, очистка логов);
     *   - маппинг результата service'а в JsonResponse.
     */
    public function makeQuery(ShowQueryRequest $request, MakeQueryService $service): JsonResponse
    {
        $data = $request->validated();
        $queryText = $service->execute($data['search_query_id']);

        return $this->getSuccessResponse('', ['query' => $queryText]);
    }
}
