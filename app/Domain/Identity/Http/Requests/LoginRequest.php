<?php

declare(strict_types=1);

namespace App\Domain\Identity\Http\Requests;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Login. Rate-limited server-side (Module 32 "brute force" / this
 * prompt's Security Review "brute force" item) — five attempts per
 * email+IP combination, matching Laravel's standard convention.
 */
final class LoginRequest extends FormRequest
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

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // Explicitly the staff session guard (ADR-002 Surface A): the
        // default guard can have been switched to a token guard, which has
        // no attempt() at all.
        if (! Auth::guard('web')->attempt($this->only('email', 'password'), true)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        // Module 30 §12 "User & Staff Oversight" (Phase B16) — a
        // platform-wide account lock a Super Admin can apply. Checked
        // AFTER a successful credential match (never reveals whether an
        // email/password pair was correct if the account happens to be
        // deactivated — same generic failure message either way) and
        // the session is torn down immediately if the account is
        // inactive, never left half-authenticated.
        if (! Auth::guard('web')->user()->is_active) {
            Auth::guard('web')->logout();
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->input('email')).'|'.$this->ip());
    }
}
