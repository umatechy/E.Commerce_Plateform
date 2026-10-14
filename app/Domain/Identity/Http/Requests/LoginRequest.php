<?php

declare(strict_types=1);

namespace App\Domain\Identity\Http\Requests;

use App\Domain\Identity\Models\User;
use Illuminate\Auth\Events\Failed;
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

    /**
     * Checks the password and, when that is all the account needs, signs
     * the user in.
     *
     * @return ?User  null when signed in; otherwise the user whose
     *                password was right and who still owes a second
     *                factor (Module 32 §8). That user is NOT signed in.
     */
    public function authenticate(): ?User
    {
        $this->ensureIsNotRateLimited();

        // Explicitly the staff session guard (ADR-002 Surface A): the
        // default guard can have been switched to a token guard.
        $guard = Auth::guard('web');
        $credentials = $this->only('email', 'password');

        // The password is checked against the staff user provider WITHOUT
        // signing in, so an account with MFA never holds a session or a
        // remember-me cookie on the strength of its password alone.
        $provider = Auth::createUserProvider((string) config('auth.guards.web.provider'))
            ?? throw new \LogicException('The staff user provider is not configured.');
        $user = $provider->retrieveByCredentials($credentials);
        $valid = $user instanceof User && $provider->validateCredentials($user, $credentials);

        // Module 30 §12 "User & Staff Oversight" (Phase B16) — a
        // platform-wide account lock a Super Admin can apply. The answer
        // is the same generic failure as a wrong password, so it never
        // reveals whether the email/password pair was correct.
        if (! $valid || ! $user->is_active) {
            event(new Failed('web', $valid ? $user : null, $credentials));
            // Phase B52 (Module 32 §58–59): watched; repeated failures raise an alert.
            app(\App\Domain\Security\Services\SecurityMonitor::class)->record('login_failed', $user instanceof User ? $user : null,
                \App\Domain\Security\Services\SecurityMonitor::maskEmail((string) $this->input('email')), context: ['inactive' => $valid], ip: $this->ip());
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        $provider->rehashPasswordIfRequired($user, $credentials);

        if ($user->hasMfaEnabled()) {
            return $user;
        }

        $guard->login($user, true);

        return null;
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));
        // Phase B52 (Module 32 §58): sign-in slowed down for this account and address.
        app(\App\Domain\Security\Services\SecurityMonitor::class)->record('login_locked', \App\Domain\Identity\Models\User::query()->where('email', (string) $this->input('email'))->first(),
            \App\Domain\Security\Services\SecurityMonitor::maskEmail((string) $this->input('email')), ip: $this->ip());

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
