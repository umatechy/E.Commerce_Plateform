<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Http\Requests;

use App\Domain\Settings\Services\StoreClock;
use Illuminate\Foundation\Http\FormRequest;

final class SavePromotionRequest extends FormRequest
{
    /**
     * The validated attributes for the Promotion row. A start or end
     * typed without an offset is wall-clock time in the store's timezone
     * (Module 33 §50); it is stored in UTC like every timestamp.
     *
     * @return array<string, mixed>
     */
    public function promotionAttributes(): array
    {
        $attributes = $this->safe()->except('target_ids');
        $clock = app(StoreClock::class);

        foreach (['starts_at', 'ends_at'] as $field) {
            if (isset($attributes[$field])) {
                $attributes[$field] = $clock->parse((string) $attributes[$field]);
            }
        }

        return $attributes;
    }

    public function authorize(): bool
    {
        return true; // enforced via Gate::authorize()/direct Policy call in the controller
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
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:percentage,fixed_amount,free_shipping'],
            'target_scope' => ['required', 'in:order,product,category,brand,collection'],
            'status' => ['sometimes', 'in:draft,active,paused,disabled,archived'],
            'percentage_value' => ['required_if:type,percentage', 'integer', 'min:1', 'max:100'],
            'fixed_amount_minor' => ['required_if:type,fixed_amount', 'integer', 'min:1'],
            'currency' => ['required_if:type,fixed_amount', 'string', 'size:3', \App\Domain\Settings\Services\Currencies::rule()],
            'min_order_value_minor' => ['nullable', 'integer', 'min:0'],
            'max_discount_minor' => ['nullable', 'integer', 'min:0'],
            'requires_coupon' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'customer_usage_limit' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            // Required only when target_scope is product/category/brand — validated in the controller (needs target_scope context alongside this array).
            'target_ids' => ['sometimes', 'array'],
            'target_ids.*' => ['integer'],
        ];
    }
}
