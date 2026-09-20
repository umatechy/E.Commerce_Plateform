# Phase B9 — Step 1: Inspection + Scope Decision (Discounts, Coupons & Promotions: Module 14)

## Inspection of Existing Code

- `Order.discount_total_minor` (B5) — column already exists, hardcoded to `0` by
  `OrderService::createOrder()` since no Promotion module existed yet. **B9 extends
  `createOrder()` additively** (new optional `discount_total_minor` key in
  `$orderData`, defaulting to `0` — fully backward compatible), so
  `grand_total_minor = subtotal_minor - discount_total_minor + shipping_total_minor`
  (the B8-established shipping term is unchanged; discount is newly subtracted).
- `OrderItem.discount_minor` (B5) — column already exists, hardcoded to `0`, never
  written to. **B9 becomes its first writer**, but only for LINE-TARGETED discounts
  (product/category/brand-scoped promotions) — see "Discount Allocation" below.
- `CheckoutService` (B6, extended B7/B8) — extended again, additively: an optional
  `coupon_code` is accepted; `PromotionService::evaluate()` (new) runs before
  `OrderService::createOrder()` and its result flows into the new
  `discount_total_minor` parameter, exactly mirroring how B8 added
  `shipping_total_minor` to the same call site.
- `EntitlementService`, `RecordsOutboxEvents`, `BaseTenantPolicy`, the atomic-
  conditional-UPDATE concurrency pattern (B2/B4/B7/B8) — all reused unchanged for
  promotion entitlement gating, promotion outbox events, `PromotionPolicy`, and
  usage-limit concurrency respectively. No parallel idempotency/concurrency system
  was built.
- `Product.type`/`Category`/`Brand` (B3) — reused directly as promotion targeting
  dimensions; no new catalog columns added.
- No existing discount/promotion/coupon logic was found anywhere in the repository
  — B9 is a clean addition, not a replacement.
- No regressions found in B0-B8 during inspection.

## Architectural Decision — Stacking & Coupon/Automatic Interaction (Module 14 §29-32, Undefined by Spec)

Module 14 explicitly lists several possible modes for both stacking and coupon-vs-
automatic-promotion interaction and says only "default behavior should be
deterministic" — it does not mandate one. Per this milestone's own Step 8
instruction ("do NOT invent a stacking policy... document the unresolved decision
rather than silently choosing a business rule"), this is documented here as an
explicit implementation decision, not silently assumed:

**Decision**: B9 implements **non-stacking by default** — at most ONE promotion
(automatic or coupon) applies to a given checkout. When both an automatic
promotion and a coupon are independently eligible, **highest benefit wins**
(whichever produces the larger discount amount on the current cart; a tie is
broken by highest `priority`, then by earliest `created_at` for full determinism —
never database row order or PHP iteration order, per Module 14 §57). This is the
conservative, safe default Module 14 §30 itself warns is necessary ("stacking
rules must prevent unintended excessive discounts"), and it is fully centralized
in one place (`PromotionEligibilityEngine::selectBestPromotion()`) so a future
milestone can replace it with a richer stacking policy without touching Checkout.

## Architectural Decision — Discount Allocation (Module 14 §61-63, Partially Undefined by Spec)

Module 14 says order-level discount ordering "must be finalized in the Financial
Blueprint" (not part of this module) and does not define a line-allocation
algorithm. **Decision**: 
- **Line-targeted promotions** (product/category/brand-scoped) allocate their
  discount directly onto the specific matching `OrderItem.discount_minor` rows —
  no proportional split is needed since the promotion already targets specific
  items.
- **Order-level and coupon-wide promotions** (percentage/fixed off the whole cart,
  free shipping) are recorded ONLY at the `Order.discount_total_minor` /
  `order_promotions` level — they are NOT allocated down into individual
  `OrderItem.discount_minor` rows. Allocating a cart-wide discount proportionally
  across lines (with correct rounding so the sum exactly equals the total) is a
  well-known but non-trivial algorithm Module 14 does not specify; inventing one
  now risks a wrong assumption baked into historical order data. This is a
  documented simplification, not an oversight.

## Architectural Decision — Usage Counting Timing (Module 14 §45)

Module 14 offers four possible counting events (Applied/Order Created/Payment
Confirmed/Order Completed) and only recommends avoiding "permanently consuming
usage from abandoned carts." **Decision**: usage is counted at **Order Created**,
inside the SAME transaction Checkout already uses for Order+Payment creation
(Phase B7's precedent). If order creation fails or rolls back, no usage is
consumed — satisfying the "abandoned cart" concern without waiting for payment
confirmation, which would incorrectly let two customers both "successfully" apply
the last coupon slot while their unconfirmed payments race.

## Architectural Decision — Cancellation/Refund Usage Restoration (Module 14 §18, prompt Step 18)

Module 14 does not explicitly define automatic usage restoration on cancellation
or refund. Per this milestone's explicit instruction ("do NOT automatically
restore coupon usage unless the specification defines that behavior"), **B9 does
NOT restore promotion usage when an order is cancelled or refunded** — a
customer's redemption is consumed permanently once their order is created,
regardless of its later fate. This is documented as a conservative, reviewed
decision, not a gap.

## Scope Decision (Module 14 spans 109 sections — same discipline as B3-B8)

**B9 implements**: `Promotion` (types: **percentage**, **fixed_amount**, **free_
shipping** — the three universally-understood, deterministic core types),
targeting scope (**order-wide**, **product**, **category**, **brand** — via a
`promotion_targets` table, reusing Phase B3's existing catalog entities, no new
catalog columns), `Coupon` (store-scoped unique code, normalized case-insensitive
comparison, start/end, usage limits, per-customer limits), automatic (no-code)
promotions evaluated alongside coupon promotions, a centralized
`PromotionEligibilityEngine` (deterministic, server-only), atomic usage-limit
concurrency (conditional `UPDATE ... WHERE used_count < usage_limit`, B2/B4/B7/B8's
established pattern), an append-only `PromotionUsage` ledger (usage counting AND
audit in one), an `order_promotions` snapshot table (Module 14 §58 — immune to
later promotion edits/deletes), minimum-order-value and maximum-discount-amount
guards, Checkout integration (coupon application, re-evaluated server-side, never
trusting a cart-time calculation), and staff-facing + minimal customer-facing APIs.

**Explicitly deferred** (named so nothing is silently dropped, given this module's
80+ sections):
- **Buy X Get Y** (§13-14) — a materially more complex trigger/reward model
  (separate trigger-product-set, reward-product-set, reward-quantity, maximum-
  applications configuration) than the three core types B9 builds. Not one of
  Module 14's own explicitly-labeled "Future" items, but deferred here as a
  genuine scope boundary — building it correctly alongside everything else in this
  milestone risked rushing its deterministic-reward-selection requirement (§14).
- **Quantity discounts** as a distinct tiered mechanism (§12) — no numeric tier
  values are given by the specification; not invented. A flat percentage/fixed
  promotion with a minimum-quantity condition is schema-ready (see below) but
  genuine multi-tier "buy 2 → 5%, buy 5 → 10%" configuration is not built.
- **Customer group / customer segment / first-order / repeat-customer promotions**
  (§17-20) — these require Module 10 customer-group/segment infrastructure that
  does not exist yet (Module 10's own §25-27 groups/segments were already deferred
  since Phase B6). Building customer-targeting promotions on top of a
  non-existent group/segment system would mean inventing that system here, out of
  this module's scope.
- **Payment-method discounts** (§16) — schema-ready as a future condition type, but
  not built: Module 14 says "payment method must be validated server-side," and
  correctly wiring this against Phase B7's `PaymentMethod` enum inside the SAME
  evaluation pass as coupon/automatic promotions was judged higher-risk to rush
  than to defer cleanly.
- **Collection discounts** (§9) — no "Collection" entity exists anywhere in this
  codebase (Catalog, Phase B3, has Category/Brand/Attribute, not Collection); not
  invented.
- **Bulk/generated coupon codes, unique-per-customer campaigns** (§49-51) — only
  manually-created coupon codes are supported; no generation algorithm or async
  bulk-creation job.
- **Scheduled/repeating promotions beyond a simple start/end date range, flash
  sales, reserved promotion capacity** (§46, §52-53) — `Promotion` has
  `starts_at`/`ends_at` (Module 14 §22) but no recurring schedule, no temporary
  capacity reservation ahead of redemption.
- **Product/customer purchase limits** (§54-55) — beyond the per-order/per-
  customer/global usage limits on the promotion/coupon itself, no separate
  "maximum discounted units" cap is enforced.
- **Promotion preview/simulation, draft/publish workflow with a version history
  table** (§65-67, §59) — `Promotion.status` includes `Draft`/`Scheduled` (schema-
  ready) but no dedicated preview endpoint or version-history table; the
  `order_promotions` snapshot itself is what protects historical orders (Module 14
  §59's actual requirement), which B9 does build.
- **Loyalty/referral/B2B/multi-currency promotion integration** (§76-79) — all
  explicitly named as depending on modules that do not exist yet.
- **Coupon enumeration protection beyond a generic error message** (§48) — the
  validation endpoint returns one generic "not eligible" message for every
  ineligibility reason (never "coupon exists but...") — this much is built; a
  dedicated rate-limit/anti-brute-force layer beyond the platform's existing
  default throttling is not.
- Admin/storefront UI (React components) — matches B6/B7/B8's own precedent of no
  frontend built in these backend-focused phases.

None of these are abandoned — each is named so Phase B10+'s own Step 1 inspection
finds this documented list.

## Bug Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

`CheckoutService::checkout()` originally ran `PromotionEligibilityEngine::evaluate()`
unconditionally on every call, including an idempotent RETRY of an already-
succeeded checkout. Because a promotion/coupon can have a finite `usage_limit`
that the ORIGINAL request itself may have just fully consumed, a legitimate retry
could re-evaluate promotions and incorrectly throw (e.g.
`CouponNotEligibleException` for "usage limit reached") even though a valid
Order/Payment already exist from the first, successful attempt. This is a new
class of idempotency bug B6/B7/B8 never encountered, because shipping rates and
payment methods have no equivalent finite, request-consumable resource. Fixed by
adding an explicit idempotent-replay short-circuit at the very top of `checkout()`
(via a new, additive `OrderService::findExistingOrderByIdempotencyKey()` read-only
method) that returns the existing Order/Payment immediately, before any promotion,
shipping, or entitlement evaluation runs.

## Second Bug Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

`CartResource` (Phase B6) did not expose the new `coupon_code`/`promotion` keys
`CartService::totals()` now returns — a client applying or removing a coupon would
have received no visible confirmation of the discount preview at all. Fixed by
adding both fields to `CartResource`'s output before any test was run against it.
