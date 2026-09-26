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

        // Anonymous (guest) request. Module 19 (Domain Management,
        // Phase B14) is now built — the AUTHORITATIVE resolution path
        // for a real storefront request is the verified Domain
        // registry, checked against the request's own Host header
        // FIRST. This can NEVER be overridden by X-Store-Slug or any
        // other client-supplied header/parameter (Module 19 Non-
        // Negotiable Step 20) — X-Store-Slug is consulted only when
        // the Host header matches NO registered, Active domain (local
        // development, this Claude App sandbox, or a client with no
        // meaningful Host of its own, e.g. a native mobile app calling
        // the API directly).
        $storeFromDomain = app(\App\Domain\Domains\Services\DomainResolverService::class)->resolveHost($request->getHost());

        if ($storeFromDomain !== null) {
            $this->context->resolveToStore($storeFromDomain->id);

            return $next($request);
        }

        // Fallback mechanism, preserved from Phase B6 exactly as
        // instructed (Module 19 Step 64: "do not rewrite B13 SEO
        // unnecessarily... existing B6 guest-store header behavior must
        // remain limited to the exact contexts where it was
        // intentionally introduced"). This is NOT a security/
        // authorization credential — a store slug is public information
        // (equivalent to a subdomain a visitor's browser would already
        // be pointed at for a real domain-resolved request), and it
        // only selects WHICH TENANT'S PUBLIC STOREFRONT to serve. It
        // grants no access to any specific guest's private data: a
        // cart's `guest_token` (a separate, high-entropy secret —
        // Module 11 §63) is the actual authorization credential for
        // that cart's contents, checked independently in
        // CartController/CartService. Knowing a store's slug alone
        // never reveals or grants access to any guest's cart. This
        // fallback can NEVER override a request whose Host header DID
        // match a verified, Active domain — that branch already
        // returned above.
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
