<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccount\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Orders\Models\Customer;
use Illuminate\Validation\ValidationException;

/**
 * Creating a customer account, shared by the token API
 * (CustomerAuthController) and the web storefront session (Phase B25),
 * so both enforce exactly the same rules.
 */
final class CustomerRegistration
{
    /**
     * @param array{name: string, email: string, password: string, phone?: ?string} $data validated
     *
     * @throws ValidationException
     */
    public function register(array $data): Customer
    {
        // Module 10 §12-style uniqueness: only among REGISTERED accounts
        // (password IS NOT NULL) — a guest Customer row from a past order
        // (Phase B5) never blocks a new registration with the same email.
        $exists = Customer::query()
            ->where('email', $data['email'])
            ->whereNotNull('password')
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['email' => 'An account with this email already exists.']);
        }

        // Phase B32 (Module 10 §31): a blocked customer cannot get round the
        // block with a new account on the same address. The answer does not
        // say why, as for a blocked sign-in.
        $blocked = Customer::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($data['email'])])
            ->where('status', \App\Domain\Customers\Models\CustomerStatus::Blocked->value)
            ->exists();
        if ($blocked) {
            throw ValidationException::withMessages(['email' => 'An account cannot be created with this email. Please contact the store.']);
        }

        $customer = Customer::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'phone' => $data['phone'] ?? null,
        ]);

        app(AuditLogger::class)->record('customer.registered', [], $customer, $customer->store_id, $customer);

        // Module 10 §9–10 (Phase B32): the confirm-your-email link goes out at
        // once. A failure to send does not undo the account; the customer can
        // ask for the link again from their account.
        try {
            $store = \App\Domain\Tenancy\Models\Store::query()->find($customer->store_id);
            if ($store !== null) {
                app(\App\Domain\Customers\Services\CustomerEmailVerification::class)->send($customer, $store);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $customer;
    }
}
