<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShowQueryRequest;
use App\Services\QueryBuilderService;
use Illuminate\Http\JsonResponse;

class QueryController extends BaseController
{
    protected QueryBuilderService $service;

    public function __construct(QueryBuilderService $service)
    {
        $this->service = $service;
    }

    public function show(ShowQueryRequest $request): JsonResponse
    {
        $id = $request->integer('id');

        $query = $this->service->searchQuery($id);

        return $this->getSuccessResponse('', [$query]);
    }
}
