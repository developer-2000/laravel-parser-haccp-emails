<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSearchQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type_business_id' => ['required', 'integer', 'exists:type_business,id'],
            'language_code'    => ['required', 'string', 'exists:languages,code'],
            'text'             => ['required', 'string', 'max:255'],
        ];
    }
}
