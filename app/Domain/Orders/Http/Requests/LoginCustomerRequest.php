<?php

declare(strict_types=1);

namespace App\Domain\Orders\Http\Requests;

use App\Domain\Orders\Models\Customer;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Same rate-limiting pattern as
 * App\Domain\Identity\Http\Requests\LoginRequest (Phase B1) — no new
 * mechanism invented.
 *
 * NOTE: Sanctum's guard driver (used by `auth:customer` middleware to
 * verify an ALREADY-ISSUED token on protected routes) has no
 * credential-based `attempt()` concept — it only validates presented
 * tokens. Login therefore verifies the email/password pair directly
 * against the tenant-scoped, REGISTERED (password IS NOT NULL)
 * Customer row via Hash::check(), then
 * CustomerAuthController::login() issues a fresh Sanctum personal
 * access token for that Customer — the same token type the future
 * Flutter client already anticipated for User accounts (ADR-002).
 */
final class LoginCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function authenticateCustomer(): Customer
    {
        $this->ensureIsNotRateLimited();

        $customer = Customer::query()
            ->where('email', $this->string('email')->toString())
            ->whereNotNull('password')
            ->first();

        if ($customer === null || ! Hash::check($this->string('password')->toString(), $customer->password)) {
            RateLimiter::hit($this->throttleKey());

            // Module 32: recorded against the store this request resolved to.
            app(\App\Domain\Compliance\Services\AuditLogger::class)->record('auth.login.failed', ['guard' => 'customer', 'email' => $this->string('email')->toString()]);

            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        RateLimiter::clear($this->throttleKey());

        // Module 10 §31/§58 (Phase B32): a blocked or archived customer cannot
        // sign in. Said only after the right password, so the answer reveals
        // nothing to someone guessing.
        if (! $customer->standing()->maySignIn()) {
            app(\App\Domain\Compliance\Services\AuditLogger::class)->record('auth.login.refused', ['guard' => 'customer', 'status' => $customer->standing()->value], $customer, $customer->store_id, $customer);

            throw ValidationException::withMessages(['email' => 'This account cannot sign in. Please contact the store.']);
        }

        return $customer;
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));
        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->input('email')).'|'.$this->ip());
    }
}
