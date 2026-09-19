<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase B6 — "optional" customer authentication for guest-accessible
 * routes (Cart/Checkout, Module 11 §6/Final Rule #3 "Guest checkout
 * must be supported"). Laravel's standard `auth:customer` middleware
 * ABORTS with 401 when no valid token is presented — the wrong
 * behavior here, since a guest legitimately has none.
 *
 * If a valid Customer Sanctum token IS presented, this resolves it and
 * calls Auth::shouldUse('customer') so that every subsequent
 * `$request->user()` call (no guard argument — used throughout
 * ResolveTenantContext, CartService, CheckoutController) correctly
 * returns the authenticated Customer for the rest of the request. If
 * no token, or an invalid one, is presented, this middleware does
 * nothing and the request proceeds as an anonymous guest — never an
 * abort.
 */
final class AttemptCustomerAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() && Auth::guard('customer')->check()) {
            Auth::shouldUse('customer');
        }

        return $next($request);
    }
}
