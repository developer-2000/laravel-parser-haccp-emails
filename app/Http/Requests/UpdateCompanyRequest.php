<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация PATCH /companies/{id} — обновление полей компании.
 * Сейчас редактируется только name; остальные поля при необходимости
 * добавлять сюда же.
 */
class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
