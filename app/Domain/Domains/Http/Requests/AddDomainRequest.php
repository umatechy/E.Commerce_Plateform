<?php

declare(strict_types=1);

namespace App\Domain\Domains\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AddDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['hostname' => ['required', 'string', 'max:253']];
    }
}
