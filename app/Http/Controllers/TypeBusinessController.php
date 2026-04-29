<?php

namespace App\Http\Controllers;

use App\Models\TypeBusiness;
use Illuminate\Http\JsonResponse;

class TypeBusinessController extends BaseController
{
    /**
     * Возвращает все типы бизнеса для использования в n-select на фронте.
     * Формат: [{ label, value }] — совместим с naive-ui SelectOption.
     */
    public function index(): JsonResponse
    {
        $items = TypeBusiness::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($t) => [
                'label' => $t->name,
                'value' => $t->id,
            ])
            ->values();

        return $this->getSuccessResponse('', ['items' => $items]);
    }

}
