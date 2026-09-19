<?php

declare(strict_types=1);

namespace App\Domain\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Module 09 §20-21: notice there is NO price/total field accepted here
 * at all — not even as an optional override. OrderService computes
 * every financial value server-side from Product/ProductVariant.
 */
final class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // enforced via Gate::authorize('create', Order::class) in the controller
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', 'required_without:items.*.product_variant_id'],
            'items.*.product_variant_id' => ['nullable', 'integer', 'required_without:items.*.product_id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],

            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'guest_name' => ['nullable', 'string', 'max:255', 'required_without:customer_id'],
            'guest_email' => ['nullable', 'email', 'max:255', 'required_without:customer_id'],
            'guest_phone' => ['nullable', 'string', 'max:32'],

            'billing_address' => ['nullable', 'array'],
            'shipping_address' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'source' => ['nullable', 'in:storefront,pwa,mobile_app,admin,pos,api,marketplace,social_commerce'],

            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
