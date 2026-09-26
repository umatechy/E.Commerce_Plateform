<?php

declare(strict_types=1);

namespace App\Domain\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'value' => ['required'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
