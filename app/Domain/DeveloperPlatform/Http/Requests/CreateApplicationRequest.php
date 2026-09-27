<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255']];
    }
}
