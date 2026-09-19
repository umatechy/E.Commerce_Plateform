<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:64'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'in:draft,active,scheduled,hidden,archived'],
            'visibility' => ['sometimes', 'in:public,catalog_only,search_only,hidden,private,scheduled'],
            'brand_id' => ['sometimes', 'nullable', 'integer', 'exists:brands,id'],
            'primary_category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'price_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'sale_price_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'cost_price_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
        ];
    }
}
