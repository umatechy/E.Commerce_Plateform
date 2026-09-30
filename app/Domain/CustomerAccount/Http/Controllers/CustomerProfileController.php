<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccount\Http\Controllers;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Orders\Http\Resources\CustomerResource;
use App\Domain\Orders\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Phase B25 — a customer's own profile, email and password. Changing the
 * email or the password requires the current password; a new password
 * signs out every other session.
 */
final class CustomerProfileController
{
    public function show(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return response()->json(['data' => [
            ...(new CustomerResource($customer))->resolve($request),
            'marketing_email_opt_in' => (bool) $customer->marketing_email_opt_in,
            'member_since' => $customer->created_at?->toIso8601String(),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\-\s]{4,32}$/'],
            'marketing_email_opt_in' => ['sometimes', 'boolean'],
        ]);

        /** @var Customer $customer */
        $customer = $request->user();
        $customer->fill($validated)->save();

        if (array_key_exists('marketing_email_opt_in', $validated)) {
            app(AuditLogger::class)->record('customer.marketing_consent_changed', ['opt_in' => (bool) $validated['marketing_email_opt_in']], $customer);
        }

        return $this->show($request);
    }

    public function changeEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'current_password' => ['required', 'string'],
        ]);

        /** @var Customer $customer */
        $customer = $request->user();
        $this->assertCurrentPassword($customer, $validated['current_password']);

        $taken = Customer::query()->where('email', $validated['email'])->whereNotNull('password')->where('id', '!=', $customer->id)->exists();
        if ($taken) {
            throw ValidationException::withMessages(['email' => 'An account with this email already exists.']);
        }

        $previous = $customer->email;
        $customer->forceFill(['email' => $validated['email'], 'email_verified_at' => null])->save();
        app(AuditLogger::class)->record('customer.email_changed', ['from' => $previous, 'to' => $customer->email], $customer);

        return $this->show($request);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        /** @var Customer $customer */
        $customer = $request->user();
        $this->assertCurrentPassword($customer, $validated['current_password']);

        $customer->forceFill(['password' => $validated['password']])->save();
        // Every other session ends; this one stays signed in.
        $customer->tokens()->where('id', '!=', $customer->currentAccessToken()->getKey())->delete();
        app(AuditLogger::class)->record('customer.password_changed', [], $customer);

        return response()->json(status: 204);
    }

    private function assertCurrentPassword(Customer $customer, string $password): void
    {
        if ($customer->password === null || ! Hash::check($password, $customer->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }
    }
}
