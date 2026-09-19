<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorization is enforced via Gate::authorize() in the controller,
 * not here (consistent with every other Request in this codebase).
 * Prices here are catalog-admin-set values, not customer-submitted
 * checkout prices (see Product::effectivePriceMinor() docblock).
 */
final class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:simple,variable,digital,service,bundle'],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:64'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,active,scheduled,hidden,archived'],
            'visibility' => ['nullable', 'in:public,catalog_only,search_only,hidden,private,scheduled'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'primary_category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'price_minor' => ['nullable', 'integer', 'min:0'],
            'sale_price_minor' => ['nullable', 'integer', 'min:0', 'lt:price_minor'],
            'cost_price_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'category_ids' => ['array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
        ];
    }
}
