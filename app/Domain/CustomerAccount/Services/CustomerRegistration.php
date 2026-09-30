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

        $customer = Customer::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'phone' => $data['phone'] ?? null,
        ]);

        app(AuditLogger::class)->record('customer.registered', [], $customer, $customer->store_id, $customer);

        return $customer;
    }
}
