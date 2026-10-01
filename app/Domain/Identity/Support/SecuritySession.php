<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;

/**
 * What the staff session remembers about HOW its user authenticated
 * (Phase B29): a pending second factor, whether MFA was passed, and when
 * the user last proved who they are (step-up, Module 30 §6).
 *
 * All of it lives in the server-side session, never in anything the
 * client sends. A request without a session (a bearer token) has none of
 * it, so it can never satisfy an MFA or step-up requirement.
 */
final class SecuritySession
{
    private const PENDING = 'auth.mfa.pending';
    private const MFA_VERIFIED_AT = 'auth.mfa_verified_at';
    private const STEP_UP_AT = 'auth.step_up_at';

    /** The password was right; the second factor is still owed. */
    public static function beginMfaChallenge(Request $request, User $user): void
    {
        $request->session()->put(self::PENDING, [
            'user_id' => $user->id,
            'expires_at' => now()->addMinutes((int) config('security.mfa.challenge_ttl_minutes'))->getTimestamp(),
        ]);
    }

    /** The id of the user who owes a second factor, while the challenge is still open. */
    public static function pendingUserId(Request $request): ?int
    {
        if (! $request->hasSession()) {
            return null;
        }

        $pending = $request->session()->get(self::PENDING);

        if (! is_array($pending) || (int) ($pending['expires_at'] ?? 0) < now()->getTimestamp()) {
            $request->session()->forget(self::PENDING);

            return null;
        }

        return (int) $pending['user_id'];
    }

    /**
     * Call right after the user is signed in. A fresh sign-in is itself
     * a recent proof of identity, so it also counts as a step-up.
     */
    public static function signedIn(Request $request, bool $withMfa): void
    {
        if (! $request->hasSession()) {
            return;
        }

        // Session-fixation protection (ADR-002 Surface A).
        $request->session()->regenerate();
        $request->session()->forget(self::PENDING);
        $request->session()->put(self::STEP_UP_AT, now()->getTimestamp());

        if ($withMfa) {
            $request->session()->put(self::MFA_VERIFIED_AT, now()->getTimestamp());
        }
    }

    public static function markMfaVerified(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::MFA_VERIFIED_AT, now()->getTimestamp());
        }
    }

    public static function forgetMfa(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::MFA_VERIFIED_AT);
        }
    }

    public static function isMfaVerified(Request $request): bool
    {
        return $request->hasSession() && $request->session()->has(self::MFA_VERIFIED_AT);
    }

    public static function markStepUp(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::STEP_UP_AT, now()->getTimestamp());
        }
    }

    public static function hasRecentStepUp(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $at = (int) $request->session()->get(self::STEP_UP_AT, 0);

        return $at > 0 && $at >= now()->subMinutes((int) config('security.step_up.ttl_minutes'))->getTimestamp();
    }
}
