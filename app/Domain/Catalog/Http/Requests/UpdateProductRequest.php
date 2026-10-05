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

    /** Currency codes are compared upper-case (owner decision 2026-10-03). */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper(trim($this->input('currency')))]);
        }
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
            'currency' => ['sometimes', 'string', 'size:3', \App\Domain\Settings\Services\Currencies::rule()],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            // Phase B39 (Module 06 §35–37): tags by name, featured, hand-picked collections.
            'is_featured' => ['sometimes', 'boolean'],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:60'],
            'collection_ids' => ['sometimes', 'array', 'max:100'],
            'collection_ids.*' => ['string', 'size:26'],
            // Phase B43 (Module 06 §36, §93).
            'sort_priority' => ['sometimes', 'integer', 'min:-1000', 'max:1000'],
            'badge_ids' => ['sometimes', 'array', 'max:20'],
            'badge_ids.*' => ['integer'],
        ];
    }
}
