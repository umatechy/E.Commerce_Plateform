<?php

declare(strict_types=1);

namespace App\Domain\Theme\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateThemeDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'config' => ['required', 'array'],
            'custom_css' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ];
    }
}
