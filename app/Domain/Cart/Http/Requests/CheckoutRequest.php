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
            'payment_method' => ['required', 'in:cod,bank_transfer,mock_redirect,jazzcash,easypaisa,test_wallet'],
            // Phase B48: the wallet to charge. Checked here, before an order exists, so a typo never leaves an unpaid order.
            'payer' => ['nullable', 'array'],
            'payer.mobile' => ['required_if:payment_method,jazzcash,easypaisa,test_wallet', 'nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && \App\Domain\Payments\Providers\PayerField::normalize(\App\Domain\Payments\Providers\PayerField::MOBILE, $value) === null) {
                    $fail('Enter the mobile number of the wallet, such as 03001234567.');
                }
            }],
            'payer.cnic_last6' => ['required_if:payment_method,jazzcash', 'nullable', 'string', 'max:10', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && \App\Domain\Payments\Providers\PayerField::normalize(\App\Domain\Payments\Providers\PayerField::CNIC_LAST6, $value) === null) {
                    $fail('Enter the last 6 digits of the CNIC the wallet is registered with.');
                }
            }],
            'shipping_method_id' => ['nullable', 'integer'], // required only when the cart is not digital-only — validated in CheckoutService (needs product-type context FormRequest rules can't see)
            'guest_name' => ['nullable', 'string', 'max:255'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:32'],
            'billing_address' => ['nullable', 'array'],
            'shipping_address' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Phase B34: "pay with my store credit". A wish, not an amount: the server decides how much.
            'use_store_credit' => ['nullable', 'boolean'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
