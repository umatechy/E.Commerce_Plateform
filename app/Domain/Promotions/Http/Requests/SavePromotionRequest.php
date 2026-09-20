<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SavePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // enforced via Gate::authorize()/direct Policy call in the controller
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:percentage,fixed_amount,free_shipping'],
            'target_scope' => ['required', 'in:order,product,category,brand'],
            'status' => ['sometimes', 'in:draft,active,paused,disabled,archived'],
            'percentage_value' => ['required_if:type,percentage', 'integer', 'min:1', 'max:100'],
            'fixed_amount_minor' => ['required_if:type,fixed_amount', 'integer', 'min:1'],
            'currency' => ['required_if:type,fixed_amount', 'string', 'size:3'],
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
