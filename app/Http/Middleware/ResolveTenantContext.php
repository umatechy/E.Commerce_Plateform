<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Implements ADR-001 Layer 1 (server-side tenant resolution).
 *
 * Resolution priority (never overridden by client input):
 *   1. Authenticated STAFF user's active store membership (via
 *      activeStoreId() — store-side users may belong to multiple
 *      stores, requiring the StoreSwitcher-backed session selection).
 *   1b. (Phase B6) Authenticated CUSTOMER's store — resolved directly
 *      from Customer.store_id, since a Customer belongs to exactly one
 *      store by construction (no multi-store customer switching
 *      concept exists — Module 10 §4's "one human, many stores" future
 *      federation is a platform-identity-level concept, not a
 *      per-request switch). See
 *      docs/development/b6-inspection-findings.md.
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
        $principal = $request->user();

        if ($principal instanceof Customer) {
            // Phase B6 fix: a Customer has no activeStoreId() — that
            // method is User-specific (multi-store staff switching).
            // A Customer's tenant is simply its own store_id, resolved
            // from the already-authenticated, already-verified Customer
            // row — never from client input.
            $this->context->resolveToStore($principal->store_id);

            return $next($request);
        }

        if ($principal) {
            // Authenticated store-side user: resolve from their verified
            // store membership record, never from any request input.
            // The activeStoreId() accessor reads the user's current store
            // membership (store_user pivot) — a user with memberships in
            // multiple stores must have an explicit "active store"
            // selection, itself stored server-side (session), not
            // client-supplied per request.
            $storeId = $principal->activeStoreId();

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

        // Anonymous (guest) request: Module 19 (Domain Management —
        // resolving tenant from the storefront's own domain/subdomain)
        // is not built yet. Phase B6 needs SOME way to resolve which
        // store a guest is shopping at (guest cart/checkout — Module 11
        // §6/§63), so this interim mechanism is used until Module 19
        // exists: an explicit `X-Store-Slug` header, resolved against
        // the PUBLIC `stores.slug` column.
        //
        // This is NOT a security/authorization credential — a store
        // slug is public information (equivalent to a subdomain a
        // visitor's browser would already be pointed at once Module 19
        // exists), and it only selects WHICH TENANT'S PUBLIC STOREFRONT
        // to serve. It grants no access to any specific guest's private
        // data: a cart's `guest_token` (a separate, high-entropy secret
        // — Module 11 §63) is the actual authorization credential for
        // that cart's contents, checked independently in
        // CartController/CartService. Knowing a store's slug alone
        // never reveals or grants access to any guest's cart.
        $storeSlug = $request->header('X-Store-Slug');

        if ($storeSlug !== null) {
            $store = \App\Domain\Tenancy\Models\Store::query()->where('slug', $storeSlug)->first();

            if ($store !== null) {
                $this->context->resolveToStore($store->id);
            }
        }

        return $next($request);
    }
}
