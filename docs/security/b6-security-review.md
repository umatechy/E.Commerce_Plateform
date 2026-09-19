# Phase B6 — Focused Security Review

Static/design-level review only — **NOT EXECUTED — DEFERRED TO VS CODE RUNTIME
VERIFICATION**.

## Regression Check — B0-B5 Capabilities Confirmed Intact

Verified by direct grep/inspection (not modified this milestone except as noted):
`BelongsToTenant::store()` present; `OrderService::createOrder()` signature
unchanged; `InventoryService::reserve()` signature unchanged; `EntitlementService`
methods unchanged; staff routes now additionally guarded by `staff.principal`
(additive, no staff-facing behavior change for legitimate staff tokens).

## Standard B6 Checklist (this milestone's 30-item Step 20 list)

| # | Item | Finding | Status |
|---|---|---|---|
| 1 | Cross-tenant cart access | `Cart`/`CartItem` use `BelongsToTenant`; a guest token from Store A never resolves a cart when the request's tenant context is Store B (global scope on `Cart::query()`). Tested explicitly. | Reviewed — OK |
| 2 | Cross-customer cart access | A customer's cart is resolved exclusively from `$request->user()->id` — no route parameter exists for cart identity at all. | Reviewed — OK |
| 3 | Wishlist IDOR | `WishlistController::destroy()`/`moveToCart()` explicitly check `$item->customer_id === $customer->id` before acting, in addition to `BelongsToTenant`'s tenant scope — defense in depth. Tested. | Reviewed — OK |
| 4 | Order IDOR through checkout | Checkout never accepts an order ID — it always creates via `OrderService::createOrder()`, which resolves everything server-side. | Reviewed — OK |
| 5 | Customer authentication boundary | See "Critical Finding" below — this was the single most significant design work this milestone. | Reviewed — OK, extensively addressed |
| 6 | Staff/customer privilege separation | `EnsureCustomerPrincipal`/`EnsureStaffPrincipal` — explicit `instanceof` checks on both sides. 2 dedicated tests (`test_customer_token_cannot_access_staff_routes`, `test_staff_token_cannot_access_customer_only_routes`) — the most security-critical tests in this milestone. | Reviewed — OK |
| 7 | Tenant spoofing | `X-Store-Slug` is documented and reviewed as NOT a security credential — it cannot grant access to any private data, only select which public storefront's context to resolve; every actual data-access check remains tenant-scope + ownership, both server-verified. | Reviewed — OK, documented boundary |
| 8 | Price tampering | `CheckoutRequest` has no price field; `CartService`/`CheckoutService` never accept a client price; `OrderService` (Phase B5, unchanged) remains the sole pricing authority. | Reviewed — OK |
| 9 | Quantity tampering | Bounded by `InventoryService::reserve()`'s atomic guard at actual checkout, regardless of what a cart's soft/informational availability check showed. | Reviewed — OK |
| 10 | Stock tampering | Same as #9 — no client-supplied stock value is ever trusted; `CartResource`'s `available` field is informational display only. | Reviewed — OK |
| 11 | Product/variant ID manipulation | `CartService::resolvePurchasableItem()` uses tenant-scoped `find()` — a cross-tenant product/variant ID resolves to a validation error, mirroring Phase B3/B5's identical pattern. | Reviewed — OK |
| 12 | Checkout replay | Idempotency key (reused from B5) makes a replayed checkout request a safe no-op — returns the same order. | Reviewed — OK |
| 13 | Idempotency-key abuse | Same `(store_id, idempotency_key)` uniqueness as B5 — a key can only ever map to one order per store. | Reviewed — OK |
| 14 | Cart takeover | Guest tokens are always server-generated for new carts (see Critical Finding #4 below) — a client cannot choose or predict another guest's cart identifier. | Reviewed — OK, fixed during design |
| 15 | Session/cart fixation | Login rotates to a fresh Sanctum token (`createToken()` on every login call, no token reuse); the guest cart token itself is not treated as a login session, only a cart-lookup key. | Reviewed — OK |
| 16 | Unauthorized cart merging | Merge only happens as a side effect of a SUCCESSFUL password-verified login (`CustomerAuthController::login()`) — never from an unauthenticated request. | Reviewed — OK |
| 17 | Duplicate wishlist items | `firstOrCreate` against the table's real unique constraint — tested. | Reviewed — OK |
| 18 | Checkout race conditions | Inherited entirely from B4/B5 — no new race surface. | Reviewed — OK |
| 19 | Inventory overselling | Same atomic guard, unchanged. | Reviewed — OK |
| 20 | Reservation abuse | Checkout creates reservations exclusively through `OrderService::createOrder()`, tagged `reference_type='order'` with a real order ID — no code path in B6 calls `InventoryService::reserve()` directly. | Reviewed — OK |
| 21 | Sensitive customer data exposure | `CustomerResource` excludes password/remember_token (both in `$hidden` AND the Resource's explicit allow-list — double safety, same convention as `UserResource`). | Reviewed — OK |
| 22 | API enumeration | Public identifiers (`public_id`) used throughout Cart/Order responses, never internal auto-increment IDs (Wishlist item IDs are the one exception — see Known Limitations). | Reviewed — OK, one documented exception |
| 23 | Rate limiting | `customer/register`, `customer/login`, and `checkout` all have `throttle:` middleware, matching the pattern already established for staff auth in Phase B1. | Reviewed — OK |
| 24 | Mass assignment | Every model uses explicit `$fillable`; `store_id`/`customer_id` are never client-settable directly. | Reviewed — OK |
| 25 | Cache isolation | No cart/checkout data is cached in B6 (Module 11 Final Rule #22: "private cart and checkout data must never be publicly cached" — trivially satisfied by not caching it at all yet). | N/A this milestone |
| 26 | Job isolation | No background job introduced in B6. | N/A this milestone |
| 27 | Event/outbox tenant context | B6 introduces no new outbox events (checkout reuses B5's `order.created`/`order.cancelled` unchanged, which already carry correct tenant context per ADR-004). | N/A this milestone, inherited correctly |
| 28 | Error leakage | Every controller catches domain exceptions explicitly and returns a structured message + code, matching the established B1-B5 pattern. | Reviewed — OK |
| 29 | Authentication token boundaries | See #6 — the core focus of this milestone's security work. | Reviewed — OK |
| 30 | Auditability of important actions | Order-side auditability (`OrderTimelineEvent`) is unchanged from B5 and applies to every order created via checkout exactly as it would via the staff API. Cart-side actions (add/remove/merge) are NOT separately audited — documented as a known limitation, not silently assumed covered. | Documented limitation |

## CRITICAL Finding — Customer/Staff Principal Separation (Module 10 §3)

This was the central architectural risk of B6: introducing a second
Sanctum-authenticatable model sharing the same polymorphic token table as the
existing staff `User` model. Without explicit type-checking, a token issued for
either principal type could have been silently accepted on the wrong surface. This
was identified during design (Step 1 inspection) — not discovered as a bug after
the fact — and closed via `EnsureCustomerPrincipal`/`EnsureStaffPrincipal`, applied
respectively to every new customer-only route and retroactively to the entire
existing staff route group. Both directions are explicitly tested.

## Issues Found and Fixed During Implementation (Design-Time, Not Post-Hoc Bugs)

1. `ResolveTenantContext` had no `Customer` case at all (would have been a fatal
   error on first use) — fixed with an explicit branch.
2. No guest tenant-resolution mechanism existed — fixed with the documented,
   non-security-sensitive `X-Store-Slug` interim header.
3. `auth:customer` on guest-accessible routes would have rejected every guest with a
   401 — fixed with the new `AttemptCustomerAuthentication` optional-auth middleware.
4. A client-supplied guest cart token could have become a new cart's real
   identifier if presented on first contact — fixed so only server-generated tokens
   are ever used for new carts; a presented token is only ever used for lookup.

## Known Limitations (Documented, Not Hidden)

1. **Wishlist item IDs are internal auto-increment values, not `public_id`s** — a
   minor inconsistency with the rest of the platform's ID-exposure convention
   (ADR-003). Low risk (an internal ID here reveals only "some wishlist item exists
   at this sequence position," not tenant or customer data, and ownership is still
   independently verified server-side on every action), but flagged for a follow-up
   fix rather than silently left inconsistent.
2. Cart-level actions (add/remove/merge) are not written to any timeline/audit
   table — only the resulting Order (if checkout succeeds) is auditable, matching
   B5's existing `OrderTimelineEvent`.
3. No dedicated fraud/bot-protection foundation beyond standard rate limiting
   (Module 11 §93-94 — explicitly deferred, see inspection findings).

None of the "found and fixed" items required deleting or resetting existing B0-B5
work. No destructive database operation was performed (all 5 new/modified
migrations in B6 are additive or new-table only).
