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

            // The store's own customers only: checked against the tenant in
            // OrderController (an `exists` rule alone sees every store).
            'customer_id' => ['nullable', 'integer'],
            'customer' => ['nullable', 'string', 'size:26'], // Phase B32: the public id the customer API returns
            'guest_name' => ['nullable', 'string', 'max:255', 'required_without_all:customer_id,customer'],
            'guest_email' => ['nullable', 'email', 'max:255', 'required_without_all:customer_id,customer'],
            // Module 09 §70 (Phase B32): an order taken by staff goes through the
            // same payment step as a storefront order. Offline methods only:
            // no gateway is connected for staff to charge a card.
            // With store credit (Phase B35) it says how the rest is paid; when the
            // credit covers everything nothing is charged, but the order still
            // gets its zero-value, paid payment.
            'payment_method' => ['nullable', 'required_if_accepted:use_store_credit', 'in:cod,bank_transfer'],
            // Phase B35: pay with the customer's store credit as far as it goes.
            'use_store_credit' => ['sometimes', 'boolean'],
            'guest_phone' => ['nullable', 'string', 'max:32'],

            'billing_address' => ['nullable', 'array'],
            'shipping_address' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'source' => ['nullable', 'in:storefront,pwa,mobile_app,admin,pos,api,marketplace,social_commerce'],

            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
