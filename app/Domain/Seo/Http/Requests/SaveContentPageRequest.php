<?php

declare(strict_types=1);

namespace App\Domain\Seo\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveContentPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash'],
            'body' => ['required', 'string', 'max:50000'],
        ];
    }
}
