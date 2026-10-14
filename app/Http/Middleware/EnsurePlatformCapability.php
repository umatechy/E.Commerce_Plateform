<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\SuperAdmin\Support\PlatformRoles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase B52 (Module 30 §3, §16–17; Module 32 §65, §67) — a Super Admin
 * request goes ahead only when the staff member's platform role has the
 * capability the route needs (PlatformRoles::capabilityFor). A route that
 * changes something and is not in PlatformRoles::ROUTES is refused: new
 * routes fail closed until they are given a capability.
 *
 * Runs after the platform-staff check of the Super Admin groups.
 */
final class EnsurePlatformCapability
{
    public function handle(Request $request, Closure $next): Response
    {
        $role = $request->user()?->platform_role;
        $uri = (string) $request->route()?->uri();
        $needed = PlatformRoles::capabilityFor($request->method(), $uri);

        if ($needed === null || ! PlatformRoles::can($role, $needed)) {
            return response()->json([
                'message' => $needed === null
                    ? 'This action has no platform permission yet, so it is refused.'
                    : 'Your platform role ('.PlatformRoles::label($role).') cannot do this. It needs: '.PlatformRoles::CAPABILITIES[$needed].'.',
                'code' => 'platform_role_forbidden',
                'capability' => $needed,
            ], 403);
        }

        return $next($request);
    }
}
