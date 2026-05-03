<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация GET /company-emails — список писем для пары (company_id + email).
 * Используется модалкой редактирования: показываем гармошку с историей.
 */
class IndexCompanyEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'email'      => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
