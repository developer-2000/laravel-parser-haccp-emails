<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexSearchQueryRequest;
use App\Http\Requests\StoreSearchQueryRequest;
use App\Http\Requests\UpdateSearchQueryRequest;
use App\Models\Language;
use App\Models\SearchQuery;
use Illuminate\Http\JsonResponse;

class SearchQueryController extends BaseController
{
    /**
     * Возвращает поисковые запросы для заданного типа бизнеса и языка.
     * Формат: [{ label, value }] — совместим с naive-ui SelectOption.
     *
     * Параметры (query string):
     *   - type_business_id: int — фильтр по типу бизнеса.
     *   - language_code: string — код языка (с фронта, из language store).
     */
    public function index(IndexSearchQueryRequest $request): JsonResponse
    {
        $data = $request->validated();

        $languageId = $this->resolveLanguageId($data['language_code']);

        $items = SearchQuery::query()
            ->where('language_id', $languageId)
            ->where('type_business_id', $data['type_business_id'])
            ->orderBy('text')
            ->get(['id', 'text'])
            ->map(fn ($q) => [
                'label' => $q->text,
                'value' => $q->id,
            ])
            ->values();

        return $this->getSuccessResponse('', ['items' => $items]);
    }

    /**
     * Создаёт новый поисковый запрос.
     * language_code приходит с фронта (из language store), резолвится в language_id.
     */
    public function store(StoreSearchQueryRequest $request): JsonResponse
    {
        $data = $request->validated();

        $languageId = $this->resolveLanguageId($data['language_code']);

        // firstOrCreate по тройке (type_business_id, language_id, text):
        // если такая комбинация уже есть в БД — возвращаем её, не плодим
        // дубликаты. Поле text — часть ключа уникальности, остальные значения
        // (если появятся новые поля) пойдут во второй массив-defaults.
        $query = SearchQuery::firstOrCreate([
            'type_business_id' => $data['type_business_id'],
            'language_id'      => $languageId,
            'text'             => $data['text'],
        ]);

        return $this->getSuccessResponse('', [
            'item' => [
                'label' => $query->text,
                'value' => $query->id,
            ],
        ]);
    }

    /**
     * Обновляет текст поискового запроса. Триггерится из модалки "Поисковый
     * запрос" по кнопке "Сохранить" в режиме редактирования.
     */
    public function update(UpdateSearchQueryRequest $request, int $id): JsonResponse
    {
        $query = SearchQuery::query()->find($id);

        if ($query === null) {
            return $this->getErrorResponse('Запрос не найден', [], 404);
        }

        $query->update($request->validated());

        return $this->getSuccessResponse('Сохранено', [
            'item' => [
                'label' => $query->text,
                'value' => $query->id,
            ],
        ]);
    }

    /**
     * Резолвит код языка ("de", "en", …) в id записи из таблицы languages.
     * Code уже валидирован (exists:languages,code) — null здесь не появится.
     */
    private function resolveLanguageId(string $code): int
    {
        return (int) Language::query()->where('code', $code)->value('id');
    }
}
