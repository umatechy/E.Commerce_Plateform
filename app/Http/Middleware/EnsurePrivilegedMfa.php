<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Support\SecuritySession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stronger authentication for the Super Admin surface (Module 32 §65.2–3,
 * Module 30 §4; SRS AUTH-012): platform staff must have MFA on, and this
 * session must have passed it. A password alone never reaches a Super
 * Admin route.
 *
 * Applied to the Super Admin route groups only. Who may do what there is
 * still decided by the `can:` gates and the Super Admin middlewares.
 */
final class EnsurePrivilegedMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! config('security.mfa.required_for_platform_staff') || $user === null || ! $user->isPlatformStaff()) {
            return $next($request);
        }

        if (! $user->hasMfaEnabled()) {
            return response()->json([
                'message' => 'Turn on two-step sign-in to use the platform administration.',
                'code' => 'mfa_enrollment_required',
            ], 403);
        }

        if (! SecuritySession::isMfaVerified($request)) {
            return response()->json([
                'message' => 'Sign in again with your two-step code to use the platform administration.',
                'code' => 'mfa_verification_required',
            ], 403);
        }

        return $next($request);
    }
}
