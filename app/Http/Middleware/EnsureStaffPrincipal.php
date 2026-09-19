<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase B6 — the symmetric counterpart to EnsureCustomerPrincipal,
 * applied RETROACTIVELY to the existing B1-B5 staff route group. Before
 * Phase B6, only `User` principals could ever authenticate via Sanctum
 * at all, so this check was unreachable-but-latent; now that `Customer`
 * is also Sanctum-authenticatable (sharing the same polymorphic token
 * table), a Customer-issued token must be explicitly rejected here
 * rather than relying on downstream code happening to fail safely if
 * it ever calls a User-only method on a non-User principal.
 *
 * No functional change for legitimate staff requests — a real staff
 * User's token passes through exactly as before.
 */
final class EnsureStaffPrincipal
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof User) {
            abort(401, 'Staff authentication required.');
        }

        return $next($request);
    }
}
