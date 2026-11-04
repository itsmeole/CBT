<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class KunciUjianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'siswa';
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pemicu' => ['required', 'string', 'in:visibilitychange,pagehide,blur,fullscreenchange,reload,close,offline'],
        ];
    }
}
