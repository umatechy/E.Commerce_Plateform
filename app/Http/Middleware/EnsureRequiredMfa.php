<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Support\SecuritySession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Store Owners must have MFA (owner decision 2026-10-01; Module 32
 * §8.1 "Tenant owners"). An owner who signed in with a password alone
 * cannot use the Store Admin surface: API calls are refused and pages
 * lead to the enrollment screen (/security).
 *
 * Applied to the whole staff API group and the admin pages. Still
 * reachable without MFA, so that enrollment is possible at all: the
 * user's own /auth/* endpoints (me, logout, MFA setup, step-up), the
 * store switch, and the Security page.
 *
 * Store staff are not required to use MFA (existing policy). Platform
 * staff are covered on the Super Admin routes by EnsurePrivilegedMfa.
 */
final class EnsureRequiredMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! config('security.mfa.required_for_store_owners') || $user === null || ! $user->ownsAStore() || $this->isExempt($request)) {
            return $next($request);
        }

        if (! $user->hasMfaEnabled()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Turn on two-step sign-in to manage your store.', 'code' => 'mfa_enrollment_required'], 403)
                : redirect('/security');
        }

        // MFA is on, but this session did not pass it. Sign-in always asks
        // for the code, so this is a session that began some other way.
        if (! SecuritySession::isMfaVerified($request)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Sign in again with your two-step code.', 'code' => 'mfa_verification_required'], 403);
            }

            Auth::guard('web')->logout();

            return redirect('/login');
        }

        return $next($request);
    }

    private function isExempt(Request $request): bool
    {
        return $request->is('api/v1/auth/*', 'api/v1/store/switch', 'security');
    }
}
