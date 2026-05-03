<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация PUT /search-queries/{id} — обновление текста поискового запроса.
 * Обновляется только text; остальные поля (type_business_id, language_id)
 * фиксируются при создании и не должны меняться.
 */
class UpdateSearchQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:255'],
        ];
    }
}
