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
            'product_id' => ['nullable', 'integer', 'required_without_all:product_variant_id,product,variant'],
            'product_variant_id' => ['nullable', 'integer'],
            // Phase B24: storefronts address items by public id (ADR-003),
            // as the storefront API publishes them; resolved in the
            // controller under the store's tenant scope.
            'product' => ['nullable', 'string', 'size:26'],
            'variant' => ['nullable', 'string', 'size:26'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ];
    }
}
