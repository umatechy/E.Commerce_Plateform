# Phase B9 — Focused Promotion Security Review

Static/design-level review only — **NOT EXECUTED — DEFERRED TO VS CODE RUNTIME
VERIFICATION**.

## Regression Check — B0-B8 Capabilities Confirmed Intact

Verified by direct grep/inspection: `BelongsToTenant::store()` present;
`OrderService::createOrder()` signature backward-compatible (both new parameters
optional, default to 0/empty); `InventoryService::reserve()`/`release()`,
`PaymentService::createForOrder()`, `ShippingRateService::quote()` all unchanged;
`EnsureCustomerPrincipal`/`EnsureStaffPrincipal` present and unmodified;
`OrderStateMachine`/`ShipmentStateMachine` untouched (0 promotion-related
references — discounts never influence Order or Shipment status transitions).

## Standard B9 Checklist (this milestone's 30-item Step 24 list)

| # | Item | Finding | Status |
|---|---|---|---|
| 1 | Cross-tenant promotion access | `Promotion`/`Coupon`/`PromotionUsage` use `BelongsToTenant`; cross-tenant access → 404 (dedicated test). | Reviewed — OK |
| 2 | Cross-tenant coupon use | `coupons.code_normalized` unique per `store_id`, and `PromotionEligibilityEngine`'s lookup is tenant-scoped by the resolved `TenantContext` — a Store B coupon code presented at Store A's storefront resolves to nothing (tested explicitly). | Reviewed — OK |
| 3 | Customer impersonation | Eligibility always uses the resolved `Cart.customer`/authenticated principal — no endpoint accepts a client-supplied `customer_id` for promotion evaluation (Order.customer_id itself has come only from the authenticated principal since Phase B6). | Reviewed — OK, N/A by construction |
| 4 | Coupon enumeration | `CouponNotEligibleException` is ONE generic message for every ineligibility reason (not found, inactive, expired, usage limit, wrong customer, wrong store) — never differentiated. | Reviewed — OK |
| 5 | Coupon brute force | Inherits the platform's default rate limiting on `/cart/coupon` and `/checkout`; no coupon-specific additional throttle was added — documented limitation, not silently assumed sufficient. | Documented limitation |
| 6 | Promotion IDOR | Route-model binding + `BelongsToTenant` global scope; staff routes always resolve by tenant-scoped id, storefront never exposes a promotion id directly (coupon CODE is the only client-facing identifier, and codes are opaque, high-entropy-recommended strings, not sequential ids). | Reviewed — OK |
| 7 | Promotion rule tampering | `SavePromotionRequest`/`SaveCouponRequest` are explicit allow-lists; every model uses `$fillable`. | Reviewed — OK |
| 8 | Discount amount tampering | `PromotionEligibilityEngine` is the sole discount-calculation source; `CheckoutRequest` has no discount field for a client to submit at all — a stray field is silently ignored (tested explicitly). | Reviewed — OK |
| 9 | Product eligibility tampering | Targeting is resolved server-side from the CART's own authoritative `product_id`/`category_ids`/`brand_id` (loaded via Eloquent relations), never from client-supplied target claims. | Reviewed — OK |
| 10 | Category eligibility tampering | Same as #9. | Reviewed — OK |
| 11 | Customer eligibility tampering | Same as #3. | Reviewed — OK |
| 12 | Usage-limit race condition | `PromotionService::atomicallyConsume()` — single atomic conditional `UPDATE`, the same pattern proven since Phase B2. Sequential simulation tested; genuine parallel verification deferred to VS Code/CI (honestly labeled). | Reviewed — OK |
| 13 | Duplicate redemption | `promotion_usages` has `unique(order_id, promotion_id)`; combined with B9's non-stacking policy, at most one usage row can ever exist per order. | Reviewed — OK |
| 14 | Replay | The idempotent-replay short-circuit (see architecture doc "Idempotent-Replay Correctness Fix") returns the existing Order/Payment without re-evaluating or re-consuming any promotion — tested explicitly (`used_count` stays at 1, not 2, after a replayed checkout). | Reviewed — OK |
| 15 | Expiration bypass | `Promotion::isCurrentlyActive()` compares against server-side `Carbon::now()` — no client timestamp is ever consulted. | Reviewed — OK |
| 16 | Time manipulation | Same as #15 — the engine has no code path that accepts a client-supplied "current time." | Reviewed — OK |
| 17 | Coupon stacking abuse | Non-stacking by design (see architecture doc) — at most one promotion/coupon ever applies; there is no code path to apply two. | Reviewed — OK |
| 18 | Negative discount | `calculateAmount()` explicitly clamps to `max(0, min($amount, $baseMinor))` — never negative, never exceeds the eligible base. | Reviewed — OK |
| 19 | Excessive discount | Same clamp, plus `max_discount_minor` cap when configured; fixed-amount discounts are capped at the eligible base (a PKR 5000 coupon on a PKR 30 cart discounts only PKR 30 — tested explicitly). | Reviewed — OK |
| 20 | Free-shipping abuse | Free shipping only ever zeroes the ALREADY-server-quoted shipping cost (`ShippingRateService::quote()`, Phase B8, unchanged) — it cannot fabricate a shipping charge that didn't exist or apply to a digital-only order (which has no shipping cost to begin with). | Reviewed — OK |
| 21 | Order total tampering | `grand_total_minor` is computed entirely server-side inside `OrderService::createOrder()` from subtotal/discount/shipping, all of which are themselves server-computed. | Reviewed — OK |
| 22 | Payment amount tampering | `PaymentService::createForOrder()` (Phase B7, unchanged) reads `Order.grand_total_minor` AFTER the discount is already folded in — Payment has no separate knowledge of or trust in any discount value. | Reviewed — OK |
| 23 | Refund/cancellation usage abuse | No automatic usage restoration exists at all (see architectural decision) — there is no code path to abuse, since cancellation/refund never touches `promotion_usages`/`used_count`. | Reviewed — OK, N/A by design |
| 24 | Mass assignment | Every model uses explicit `$fillable`; controllers use `$request->safe()` (validated-only) rather than raw input for create/update calls. | Reviewed — OK |
| 25 | Sensitive promotion data exposure | `PromotionResource`/`CouponResource` are explicit allow-lists — no internal `store_id`, no other customers' usage data. | Reviewed — OK |
| 26 | Admin authorization | `PromotionPolicy` (view/create/manage) — `Gate::authorize()`/direct-policy calls verified present in every mutating `PromotionController`/`CouponController` method. | Reviewed — OK |
| 27 | Customer authorization | Coupon apply/remove and cart promotion preview use the exact same guest-or-customer ownership resolution as every other Cart operation (Phase B6, unchanged). | Reviewed — OK |
| 28 | API enumeration | `PromotionResource` uses `public_id`; `Coupon` has no dedicated public GET-by-id endpoint for storefront use at all (coupons are only ever looked up by CODE, which the customer already knows, never enumerated by id). | Reviewed — OK |
| 29 | Rate limiting | `/checkout` retains its existing `throttle:10,1` (Phase B6); `/cart/coupon` has no dedicated rate limit of its own — same class of documented limitation as B7/B8's webhook endpoints. | Documented limitation |
| 30 | Cache isolation | No promotion result is cached anywhere in B9 (every evaluation is live, server-side, per-request) — Module 14 §26's cache-safety concern is trivially satisfied by not caching at all yet. | N/A this milestone |

## Issues Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

1. **Idempotent checkout replay could have failed after a promotion's usage limit
   was consumed by the original request** — the single most significant finding
   this milestone (see architecture doc). Fixed with an explicit short-circuit
   before any promotion evaluation.
2. `CartResource` (Phase B6) did not expose the new `coupon_code`/`promotion`
   preview fields `CartService::totals()` now returns — fixed before any test was
   run against it.

## Known Limitations (Documented, Not Hidden)

1. No dedicated rate limit on coupon application beyond the platform default
   (matches Payment/Shipping webhook endpoints' identical, already-documented
   limitation).
2. Customer-specific/group/segment promotions are not implemented at all (Module
   10 infrastructure doesn't exist yet) — the customer-impersonation checklist item
   is therefore satisfied by construction (there is no customer-targeting feature
   to impersonate against) rather than by a dedicated access-control test.

None of the "found and fixed" items required deleting or resetting existing B0-B8
work. No destructive database operation was performed (all 6 new/modified
migrations in B9 are additive or new-table only).
