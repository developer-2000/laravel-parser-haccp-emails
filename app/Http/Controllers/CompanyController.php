<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Models\Company;
use Illuminate\Http\JsonResponse;

class CompanyController extends BaseController
{
    /**
     * Возвращает список компаний для заданного SearchQuery.
     *
     * Параметр (query string):
     *   - search_query_id: int — фильтр по поисковому запросу.
     *
     * Связь язык+тип бизнеса инкапсулирована в SearchQuery (его FK поля),
     * поэтому фронту не нужно фильтровать по language/type-business явно.
     */
    public function index(IndexCompanyRequest $request): JsonResponse
    {
        $data = $request->validated();

        $withTrashed = (bool) ($data['with_trashed'] ?? false);

        $items = Company::query()
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->where('search_query_id', $data['search_query_id'])
            // Подтягиваем только адреса из company_emails — этого достаточно,
            // чтобы на фронте отметить, на какие email уже было отправлено
            // письмо (иконка-конверт). Сами тексты писем сейчас не нужны.
            ->with(['companyEmails:id,company_id,email'])
            ->orderBy('id')
            ->get(['id', 'url', 'name', 'emails', 'tier', 'deleted_at'])
            ->map(fn ($c) => [
                'id'         => $c->id,
                'url'        => $c->url,
                'name'       => $c->name,
                'tier'       => $c->tier,
                // Каст 'emails' => 'array' в модели уже отдаёт массив, но на
                // случай null'а (компании без найденных адресов) возвращаем []
                // — на фронте удобно работать с массивом всегда.
                'emails'     => $c->emails ?? [],
                // Адреса, для которых уже есть запись в company_emails —
                // фронт по этому списку рисует иконку рядом с email'ом.
                // unique() — на случай нескольких писем на один адрес.
                'sent_emails' => $c->companyEmails->pluck('email')->unique()->values()->all(),
                // null для активных, ISO-строка для мягко удалённых —
                // фронт по этому полю решает, как подсветить строку.
                'deleted_at' => $c->deleted_at?->toIso8601String(),
            ])
            ->values();

        return $this->getSuccessResponse('', ['items' => $items]);
    }

    /**
     * Обновление полей компании. Сейчас редактируется только name из модалки
     * на главной (кнопка-карандаш в action-колонке).
     */
    public function update(UpdateCompanyRequest $request, int $id): JsonResponse
    {
        $company = Company::query()->find($id);

        if ($company === null) {
            return $this->getErrorResponse('Компания не найдена', [], 404);
        }

        $company->update($request->validated());

        return $this->getSuccessResponse('Сохранено', [
            'item' => [
                'id'   => $company->id,
                'name' => $company->name,
            ],
        ]);
    }

    /**
     * Мягкое удаление компании: проставляет deleted_at, запись остаётся в БД,
     * но из обычных выборок исчезает (благодаря SoftDeletes в модели).
     */
    public function destroy(int $id): JsonResponse
    {
        $company = Company::query()->find($id);

        if ($company === null) {
            return $this->getErrorResponse('Компания не найдена', [], 404);
        }

        $company->delete();

        return $this->getSuccessResponse('', ['id' => $id]);
    }
}
