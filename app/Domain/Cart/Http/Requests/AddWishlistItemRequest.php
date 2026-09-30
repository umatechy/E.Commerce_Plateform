<?php

declare(strict_types=1);

namespace App\Domain\Cart\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AddWishlistItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['nullable', 'integer', 'required_without_all:product_variant_id,product,variant'],
            'product_variant_id' => ['nullable', 'integer'],
            // Phase B25: storefronts address items by public id (ADR-003).
            'product' => ['nullable', 'string', 'size:26'],
            'variant' => ['nullable', 'string', 'size:26'],
        ];
    }
}
