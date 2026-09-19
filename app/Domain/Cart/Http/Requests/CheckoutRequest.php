<?php

declare(strict_types=1);

namespace App\Domain\Cart\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Module 11 §20-21: notice there is NO price/total field accepted here
 * — identical discipline to Phase B5's CreateOrderRequest. Guest
 * contact fields are required only when no authenticated Customer
 * exists (enforced in the controller, since FormRequest rules alone
 * cannot see the resolved guard's principal cleanly here).
 */
final class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_method' => ['required', 'in:cod,bank_transfer,mock_redirect'],
            'shipping_method_id' => ['nullable', 'integer'], // required only when the cart is not digital-only — validated in CheckoutService (needs product-type context FormRequest rules can't see)
            'guest_name' => ['nullable', 'string', 'max:255'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:32'],
            'billing_address' => ['nullable', 'array'],
            'shipping_address' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
