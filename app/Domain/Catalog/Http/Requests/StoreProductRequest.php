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

    /**
     * Currency codes are compared upper-case; a product without one gets
     * the store's currency (before B32 follow-up it stayed empty, and an
     * order for it could not be saved: orders.currency is required).
     */
    protected function prepareForValidation(): void
    {
        $currency = $this->input('currency');
        $this->merge(['currency' => is_string($currency) && trim($currency) !== ''
            ? strtoupper(trim($currency))
            : (string) app(\App\Domain\Settings\Services\ConfigService::class)->get('store.default_currency')]);
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
            // One of the platform's currencies; without one, the store's own (owner decision 2026-10-03).
            'currency' => ['required', 'string', 'size:3', \App\Domain\Settings\Services\Currencies::rule()],
            'category_ids' => ['array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            // Phase B39 (Module 06 §35–37): tags by name, featured, hand-picked collections.
            'is_featured' => ['boolean'],
            'tags' => ['array', 'max:20'],
            'tags.*' => ['string', 'max:60'],
            'collection_ids' => ['array', 'max:100'],
            'collection_ids.*' => ['string', 'size:26'],
            // Phase B43 (Module 06 §36, §93).
            'sort_priority' => ['sometimes', 'integer', 'min:-1000', 'max:1000'],
            'badge_ids' => ['sometimes', 'array', 'max:20'],
            'badge_ids.*' => ['integer'],
        ];
    }
}
