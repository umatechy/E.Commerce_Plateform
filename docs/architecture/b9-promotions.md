# Phase B9 — Discounts, Coupons & Promotions Architecture (Module 14)

See `docs/development/b9-inspection-findings.md` for the scope decision and four
architectural decisions (stacking, discount allocation, usage counting timing,
cancellation/refund restoration).

## Entities

- **Promotion** — 3 core types (Module 14 §5-6/§15): percentage, fixed_amount,
  free_shipping. Target scope: order/product/category/brand. "Dumb" model;
  `PromotionService` is the only writer of `used_count`.
- **PromotionTarget** — one row per targeted product/category/brand id.
- **Coupon** — store-scoped unique normalized code (§24-25), independent usage
  limits layered on top of the parent Promotion's own limits.
- **PromotionUsage** — append-only, doubling as usage-counting AND audit ledger
  (same 2-in-1 pattern as Phase B7's `PaymentTransaction`).
- **OrderPromotion** — immutable historical snapshot (§58-59): editing or deleting
  the live `Promotion` later never changes what an existing Order shows.

## Stacking & Coupon/Automatic Interaction (Undefined by Spec — Documented Decision)

**Non-stacking by default**: at most ONE promotion applies per checkout. When both
an automatic promotion and an applied coupon are eligible, **highest benefit wins**
— compared in real minor-unit terms (free shipping's "benefit" is the actual
shipping cost it would waive, passed into `evaluate()` as `$shippingCostMinor`, so
it is genuinely comparable to a cash discount). Ties break by `priority` desc, then
`created_at` asc — never database row order or PHP iteration order (Module 14
§57's explicit determinism requirement). Fully centralized in
`PromotionEligibilityEngine::evaluate()`'s `usort()` comparator — a future
milestone can replace this with a richer stacking policy without touching
Checkout.

## Discount Allocation (Partially Undefined by Spec — Documented Decision)

- **Line-targeted** (product/category/brand): allocated directly onto matching
  `OrderItem.discount_minor` rows, proportional to each line's share of the
  eligible base, with the rounding remainder absorbed by the last matching line so
  the sum always exactly equals the computed total discount (never over- or
  under-allocates).
- **Order-level and coupon-wide**: recorded only at `Order.discount_total_minor` /
  `order_promotions` — never split down into individual `OrderItem.discount_minor`
  rows (a proportional-rounding algorithm across the WHOLE cart is a well-known but
  non-trivial problem Module 14 does not specify).

## Server-Authoritative Discount, Never Client Input

`PromotionEligibilityEngine::evaluate()` is the ONLY place a discount amount is
computed. `CheckoutService`/`CartService` both call this SAME method (preview vs.
authoritative) — no duplicated calculation logic. `CheckoutRequest` has no
discount/promotion field at all for the client to submit; a stray field (e.g.
`discount_total_minor`) is simply ignored (tested explicitly).

## Order Integration — Two Additive `OrderService::createOrder()` Extensions

- `discount_total_minor` (new key in `$orderData`, default 0) →
  `grand_total_minor = subtotal_minor - discount_total_minor + shipping_total_minor`
  (the B8-established shipping term is unchanged).
- `line_discounts` (new key, `[cart-item-index => discount_minor]`) applied during
  the existing per-item creation loop, setting `OrderItem.discount_minor` and
  reducing `line_total_minor` accordingly.

Both are fully backward compatible — every pre-B9 caller that omits them behaves
exactly as before.

## Idempotent-Replay Correctness Fix

`CheckoutService::checkout()` short-circuits at the very top when an Order already
exists for the given idempotency key (via a new, additive
`OrderService::findExistingOrderByIdempotencyKey()`), returning the existing Order/
Payment immediately — BEFORE any promotion evaluation runs. This closes a real bug:
promotions (unlike shipping rates or payment methods) can have a finite usage limit
the ORIGINAL request itself may have just consumed, which would otherwise make a
legitimate retry fail with "usage limit reached" even though a valid Order/Payment
already exist.

## Usage-Limit Concurrency

`PromotionService::atomicallyConsume()` — the same atomic-conditional-`UPDATE`
strategy as every other balance mutation in this codebase since Phase B2
(`UsageTrackingService`): `UPDATE ... WHERE used_count < usage_limit` (or no
restriction when unlimited), decided by the database via affected-row count, never
a prior `SELECT`. Applied independently to both the Promotion's own limit and (if
a coupon was used) the Coupon's own limit.

## Usage Counting Timing

Counted at **Order Created**, inside the same transaction as Checkout's Order +
Payment creation. If order creation fails or rolls back, no usage is consumed.

## Cart Integration

`Cart.coupon_code` — a customer applies/removes a coupon via dedicated endpoints
(`POST`/`DELETE /api/v1/cart/coupon`), validated lightly at apply-time via the same
`PromotionEligibilityEngine`. `CartResource`/`CartService::totals()` surface a live
`promotion` preview (`applied`, `discount_amount_minor`, `free_shipping`) — never
authoritative; Checkout always re-evaluates from scratch.

## Payment & Shipping Boundaries Preserved

`PaymentService::createForOrder()` (Phase B7) is called completely unchanged —
it reads `Order.grand_total_minor`, which already has the discount folded in by
the time Payment is created, so Payment never sees or needs to know a promotion
was applied. `ShippingRateService::quote()` (Phase B8) is called completely
unchanged — the promotion engine only decides whether to zero the shipping cost
AFTER Shipping's own authoritative quote is computed, never bypassing it.

## Cancellation/Refund

No automatic usage restoration — a customer's redemption is consumed permanently
once their order is created, regardless of its later cancellation/refund fate
(documented, conservative default per Module 14's silence on this point).

## API Endpoints Added in B9

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET/POST/PUT | `/api/v1/promotions[/{id}]` | staff (`promotions.manage`/`view`) | |
| GET | `/api/v1/promotions/{id}/coupons` | staff | |
| POST | `/api/v1/coupons` | staff | |
| DELETE | `/api/v1/coupons/{id}` | staff | soft-disable, never hard-delete |
| POST/DELETE | `/api/v1/cart/coupon` | optional (guest/customer) | |

## UI

Not built in B9, matching B6/B7/B8's own precedent — no storefront/admin frontend
exists yet in this pass. Deferred, not silently dropped.

## Deferred (see inspection findings for the full, explicit list)

Buy X Get Y, tiered quantity discounts, customer group/segment/first-order/repeat-
customer promotions (Module 10 groups/segments don't exist yet), payment-method
discounts, collection discounts (no Collection entity exists), bulk/generated
coupon codes, scheduled/recurring promotions and flash sales, product/customer
purchase limits, promotion preview/simulation and version history, loyalty/
referral/B2B/multi-currency integration, dedicated coupon-brute-force rate
limiting beyond the platform default, admin/storefront UI.
