<?php

declare(strict_types=1);

namespace App\Domain\Cart\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['quantity' => ['required', 'integer', 'min:1']];
    }
}
