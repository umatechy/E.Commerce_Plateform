<?php

declare(strict_types=1);

namespace App\Domain\Cart\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AddCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership enforced in the controller (identity match, not a Policy — see b6-inspection-findings.md)
    }

    public function rules(): array
    {
        return [
            'product_id' => ['nullable', 'integer', 'required_without:product_variant_id'],
            'product_variant_id' => ['nullable', 'integer', 'required_without:product_id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
