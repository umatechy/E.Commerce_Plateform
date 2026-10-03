<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Orders\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase B6 — see docs/development/b6-inspection-findings.md "Critical
 * Architectural Decision". Sanctum's token guard resolves a token's
 * `tokenable` polymorphically regardless of which named guard checked
 * it — `auth:customer` alone does NOT guarantee the resolved principal
 * is actually a Customer (a staff User's token would also pass a bare
 * guard check). This middleware makes that guarantee explicit: a
 * non-Customer principal (or none at all) is rejected with 401, never
 * silently treated as "close enough."
 *
 * Applied to every B6 customer-facing route, always AFTER
 * `auth:customer` in the middleware chain.
 */
final class EnsureCustomerPrincipal
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof Customer) {
            abort(401, 'Customer authentication required.');
        }

        // Module 10 §31 (Phase B32): blocking ends the sessions; this also
        // covers a token issued in the moment before.
        if (! $request->user()->standing()->maySignIn()) {
            abort(403, 'This account cannot be used. Please contact the store.');
        }

        return $next($request);
    }
}
