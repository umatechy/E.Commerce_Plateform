<?php

declare(strict_types=1);

namespace App\Domain\Seo\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveRedirectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_path' => ['required', 'string', 'max:2048'],
            'destination_path' => ['required', 'string', 'max:2048'],
            'status_code' => ['sometimes', 'in:301,302,307,308'],
        ];
    }
}
