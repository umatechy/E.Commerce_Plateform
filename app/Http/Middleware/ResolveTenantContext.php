<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Implements ADR-001 Layer 1 (server-side tenant resolution).
 *
 * Resolution priority (never overridden by client input):
 *   1. Authenticated user's active store membership (store-side users).
 *   2. An explicitly authorized Super Admin impersonation session
 *      (separately permission-checked — see EnsureSuperAdminImpersonation).
 *   3. The resolved public storefront domain/subdomain (Module 19),
 *      for anonymous storefront requests — resolved via the Domain
 *      Management module's domain->store lookup, never trusted from a
 *      client-supplied header.
 *
 * This middleware MUST run before any controller, policy, or model query
 * that touches tenant-owned data. Registered in bootstrap/app.php as part
 * of both the 'web' (Inertia storefront/admin) and 'api' (v1) middleware
 * groups. It is intentionally NOT applied to the Developer API group
 * (api-dev), which resolves tenant from the API credential instead — see
 * ResolveDeveloperApiTenantContext (Module 31 / Phase 5, not yet built).
 */
final class ResolveTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            // Authenticated store-side user: resolve from their verified
            // store membership record, never from any request input.
            // The activeStoreId() accessor reads the user's current store
            // membership (store_user pivot) — a user with memberships in
            // multiple stores must have an explicit "active store"
            // selection, itself stored server-side (session), not
            // client-supplied per request.
            $storeId = $request->user()->activeStoreId();

            if ($storeId !== null) {
                $this->context->resolveToStore($storeId);
            } else {
                // Authenticated but no resolvable store membership: leave
                // context unresolved. Any tenant-scoped query downstream
                // will fail loudly (TenantContextMissingException) rather
                // than silently proceeding — this is deliberate.
            }

            return $next($request);
        }

        // Anonymous request: attempt storefront domain resolution.
        // DomainResolver is owned by Module 19 (Domain Management) and is
        // intentionally out of scope for Phase B0/B1 — this middleware
        // defines the *contract* now so Module 19's implementation has a
        // stable integration point later, without re-litigating this
        // middleware's responsibilities.
        // $storeId = app(DomainResolver::class)->resolveFromHost($request->getHost());
        // if ($storeId !== null) { $this->context->resolveToStore($storeId); }

        return $next($request);
    }
}
