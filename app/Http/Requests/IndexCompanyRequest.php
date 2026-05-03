<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация GET /companies — список компаний для конкретного SearchQuery.
 *
 * Связь "язык + тип бизнеса" уже сидит внутри SearchQuery (search_query.language_id
 * и type_business_id), поэтому фронту достаточно передать search_query_id —
 * фильтрация по парк (lang+type) автоматическая через эту FK.
 */
class IndexCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search_query_id' => ['required', 'integer', 'exists:search_query,id'],
            // Включать ли в выдачу мягко удалённые записи. По умолчанию false →
            // SoftDeletes сам их прячет.
            'with_trashed'    => ['nullable', 'boolean'],
        ];
    }
}
