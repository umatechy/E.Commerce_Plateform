<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Support\SecuritySession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step-up authentication (Module 30 §6, Module 32 §9.10, §65.5;
 * SRS SA-004): a sensitive action needs the user to have proven who
 * they are recently — at sign-in, or again through POST /auth/step-up.
 *
 * It never replaces authorization: the route's policy and permission
 * checks still decide whether the user may do the action at all.
 */
final class RequireStepUp
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.step_up.enabled') || SecuritySession::hasRecentStepUp($request)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Confirm your password to continue.',
            'code' => 'step_up_required',
        ], 403);
    }
}
