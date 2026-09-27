<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class IssueApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }
}
