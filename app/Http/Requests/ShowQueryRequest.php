<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShowQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'integer', 'exists:query_sets,id'],
        ];
    }
}
