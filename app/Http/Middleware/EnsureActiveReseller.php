<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Support\SecuritySession;
use App\Domain\Resellers\Services\ResellerDirectory;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase B58 (Module 28 §30, §77, §111) — the partner portal API: only the
 * account linked to an active or paused reseller, and only with two-step
 * sign-in on and passed in this session (resellers create stores and are
 * paid). A paused reseller can look; creating stores refuses it. A
 * terminated reseller's access ends at once (§72).
 *
 * The reseller is put on the request; controllers never take a reseller id
 * from the client, so one reseller can never reach another's data.
 */
final class EnsureActiveReseller
{
    public function __construct(private readonly ResellerDirectory $directory) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $reseller = $user === null ? null : $this->directory->forUser($user);

        if ($reseller === null || ! in_array($reseller->status, ['active', 'paused'], true)) {
            return response()->json(['message' => 'This account has no open partner account.', 'code' => 'not_a_reseller'], 403);
        }
        if (! $user->hasMfaEnabled()) {
            return response()->json(['message' => 'Turn on two-step sign-in to use the partner portal.', 'code' => 'mfa_enrollment_required'], 403);
        }
        if (! SecuritySession::isMfaVerified($request)) {
            return response()->json(['message' => 'Sign in again with your two-step code to use the partner portal.', 'code' => 'mfa_verification_required'], 403);
        }

        $request->attributes->set('reseller', $reseller);

        return $next($request);
    }
}
