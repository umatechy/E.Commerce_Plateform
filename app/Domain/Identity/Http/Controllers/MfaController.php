<?php

declare(strict_types=1);

namespace App\Domain\Identity\Http\Controllers;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\InvalidMfaCodeException;
use App\Domain\Identity\Services\MfaService;
use App\Domain\Identity\Support\SecuritySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * A staff user's own multi-factor authentication and step-up
 * (Module 32 §8, Module 30 §6; SRS AUTH-006, AUTH-007, SA-004).
 * Everything here acts on the signed-in user only — there is no user id
 * in any of these routes.
 */
final class MfaController
{
    public function __construct(private readonly MfaService $mfa) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->status($request->user())]);
    }

    /** Step 1: a new secret for the authenticator app. Needs the password. */
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->confirmPassword($request, $user);

        if ($user->hasMfaEnabled()) {
            return response()->json(['message' => 'Two-step sign-in is already on.', 'code' => 'mfa_already_enabled'], 409);
        }

        return response()->json(['data' => $this->mfa->beginEnrollment($user)]);
    }

    /** Step 2: a code from the app proves it has the secret. Returns the recovery codes once. */
    public function confirm(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate(['code' => ['required', 'string', 'max:32']]);

        if ($user->hasMfaEnabled() || $user->mfa_secret === null) {
            return response()->json(['message' => 'Start the setup again.', 'code' => 'mfa_setup_not_started'], 409);
        }

        try {
            $codes = $this->mfa->confirmEnrollment($user, $request->string('code')->toString());
        } catch (InvalidMfaCodeException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        // The user has just proven both factors in this session.
        SecuritySession::markMfaVerified($request);
        SecuritySession::markStepUp($request);

        return response()->json(['data' => [...$this->status($user->refresh()), 'recovery_codes' => $codes]]);
    }

    /** New recovery codes; the old ones stop working. Needs the password and a code. */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireEnabled($user);
        $this->confirmPassword($request, $user);
        $this->verifyCode($request, $user, 'recovery_codes');

        return response()->json(['data' => [...$this->status($user), 'recovery_codes' => $this->mfa->regenerateRecoveryCodes($user)]]);
    }

    /** Turns MFA off. Needs the password and a code (Module 32 §8.6). */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->requireEnabled($user);
        $this->confirmPassword($request, $user);
        $this->verifyCode($request, $user, 'disable');

        $this->mfa->disable($user);
        SecuritySession::forgetMfa($request);

        return response()->json(['data' => $this->status($user->refresh())]);
    }

    /**
     * Step-up (Module 30 §6): prove who you are again before a sensitive
     * action. The password, plus a code when MFA is on.
     */
    public function stepUp(Request $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();

        if (! $request->hasSession()) {
            return response()->json(['message' => 'Re-authentication needs a browser session.', 'code' => 'session_required'], 409);
        }

        $this->confirmPassword($request, $user);

        if ($user->hasMfaEnabled()) {
            $this->verifyCode($request, $user, 'step_up');
            SecuritySession::markMfaVerified($request);
        }

        SecuritySession::markStepUp($request);
        $audit->record('auth.step_up.passed', ['mfa' => $user->hasMfaEnabled()], $user, actor: $user, platform: true);

        return response()->json(['data' => ['valid_for_minutes' => (int) config('security.step_up.ttl_minutes')]]);
    }

    /** @return array<string, mixed> */
    private function status(User $user): array
    {
        return [
            'enabled' => $user->hasMfaEnabled(),
            'confirmed_at' => $user->mfa_confirmed_at?->toIso8601String(),
            'recovery_codes_remaining' => $user->hasMfaEnabled() ? $this->mfa->remainingRecoveryCodes($user) : 0,
            // Platform staff and store owners must have it (User::mustUseMfa).
            'required' => $user->mustUseMfa(),
        ];
    }

    private function requireEnabled(User $user): void
    {
        abort_unless($user->hasMfaEnabled(), 409, 'Two-step sign-in is not on.');
    }

    private function confirmPassword(Request $request, User $user): void
    {
        $request->validate(['password' => ['required', 'string']]);
        $key = "password-confirm:{$user->id}";

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['password' => trans('auth.throttle', ['seconds' => RateLimiter::availableIn($key), 'minutes' => ceil(RateLimiter::availableIn($key) / 60)])]);
        }

        if (! Hash::check($request->string('password')->toString(), (string) $user->password)) {
            RateLimiter::hit($key, 60);
            app(AuditLogger::class)->record('auth.password_confirmation.failed', ['route' => $request->path()], $user, actor: $user, platform: true);

            throw ValidationException::withMessages(['password' => trans('auth.password')]);
        }

        RateLimiter::clear($key);
    }

    private function verifyCode(Request $request, User $user, string $purpose): void
    {
        $request->validate(['code' => ['required', 'string', 'max:32']]);

        try {
            $this->mfa->verify($user, $request->string('code')->toString(), $purpose);
        } catch (InvalidMfaCodeException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }
    }
}
