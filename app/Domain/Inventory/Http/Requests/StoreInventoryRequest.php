<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id', 'required_without:product_variant_id'],
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id', 'required_without:product_id'],
            'reorder_point' => ['nullable', 'integer', 'min:0'],
            'reorder_quantity' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
