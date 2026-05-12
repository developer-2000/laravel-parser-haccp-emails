<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация POST /company-emails — сохранение письма (letter) для пары
 * (company_id + email). Email должен соответствовать одному из адресов,
 * сохранённых в companies.emails (массив JSON), но эта инвариантность
 * проверяется уже в контроллере, чтобы валидатор не лез в JSON-поле.
 */
class StoreCompanyEmailRequest extends FormRequest
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
            'title'      => ['required', 'string', 'max:255'],
            'letter'     => ['required', 'string'],
        ];
    }
}
