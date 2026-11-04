<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class KoreksiEssayRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'skor' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'catatan' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
