============================================================
PHASE B9 CHECKPOINT
============================================================

Phase:
Development Phase B

Module:
B9 — Discounts, Coupons & Promotions (Module 14)

Implementation Summary:
Implemented a server-authoritative, deterministic promotion engine layered over
the existing Cart/Checkout (B6), Order (B5), Payment (B7), and Shipping (B8)
domains without duplicating any of their logic: Promotion (percentage/fixed_
amount/free_shipping, targeting order/product/category/brand), Coupon (store-
scoped normalized codes), a centralized PromotionEligibilityEngine (non-stacking,
deterministic highest-benefit selection), atomic usage-limit concurrency, an
append-only usage ledger doubling as audit trail, and an immutable order_
promotions snapshot table. Checkout now evaluates promotions/coupons and folds
the resulting discount into Order.grand_total_minor via two new additive
OrderService parameters. Runtime execution remains deferred to VS Code - nothing
in this milestone has been executed against a real PHP/MySQL runtime.

Bugs Found and Fixed (design-time, caught before being left in the codebase):
1. CheckoutService::checkout() originally ran promotion evaluation
   unconditionally, including on an idempotent RETRY of an already-succeeded
   checkout. Because a promotion/coupon can have a finite usage_limit the
   ORIGINAL request itself may have just consumed, a legitimate retry could
   incorrectly throw (e.g. "coupon usage limit reached") even though a valid
   Order/Payment already existed. Fixed with an explicit idempotent-replay
   short-circuit at the very top of checkout(), via a new, additive
   OrderService::findExistingOrderByIdempotencyKey() method, that returns the
   existing Order/Payment before any promotion, shipping, or entitlement
   evaluation runs.
2. CartResource (Phase B6) did not expose the new coupon_code/promotion preview
   fields CartService::totals() now returns - fixed before any test was run
   against it.

Architectural Decisions (all explicitly undefined or only partially defined by
Module 14, documented per this milestone's own Step 8/13 instructions rather
than silently assumed):
- Stacking: non-stacking by default. At most one promotion applies per
  checkout; when both an automatic promotion and a coupon are eligible, highest
  benefit wins (compared in real minor-unit terms, including free shipping's
  waived cost); ties break by priority desc, then created_at asc - never
  database row order or PHP iteration order.
- Discount allocation: line-targeted promotions (product/category/brand)
  allocate proportionally onto matching OrderItem.discount_minor rows, with
  rounding remainder absorbed by the last matching line; order-level/coupon-wide
  discounts stay at the Order level only, never split across lines.
- Usage counting timing: at Order Created, inside the same transaction as
  Checkout's Order+Payment creation - avoids permanently consuming usage from
  abandoned carts without waiting all the way to payment confirmation.
- No automatic usage restoration on cancellation/refund - a redemption is
  permanent once the order is created.

Promotion Domain:
Promotion (3 types: percentage, fixed_amount, free_shipping; 4 target scopes:
order, product, category, brand), PromotionTarget (one row per targeted
product/category/brand), Coupon, PromotionUsage (append-only, usage-counting +
audit in one), OrderPromotion (immutable historical snapshot).

Coupon Architecture:
Store-scoped unique normalized (uppercased, trimmed) codes - Store A and Store B
may independently use the same code. Independent usage_limit/customer_usage_
limit layered on top of the parent Promotion's own limits. Deactivation soft-
disables (is_active = false), never hard-deletes a coupon that may already have
usage history.

Eligibility Engine:
PromotionEligibilityEngine::evaluate() is the ONLY place eligibility and discount
amounts are decided - CartService (preview) and CheckoutService (authoritative)
both call this exact same method. Every input (customer, cart contents, coupon
code) is server-loaded; the client never supplies an eligibility result or
discount amount.

Promotion Types:
Percentage discount, fixed amount discount, free shipping - the 3 core,
deterministic types Module 14 lists. Buy X Get Y, quantity tiers, customer
group/segment/first-order/repeat-customer, and payment-method discounts are
explicitly deferred (see inspection findings for full rationale).

Stacking/Priority Behavior:
Non-stacking; highest-benefit-wins selection with deterministic priority/
created_at tie-breaking, as described above.

Usage-Limit Concurrency Strategy:
Single atomic conditional UPDATE (UPDATE ... WHERE used_count < usage_limit),
decided by the database via affected-row count, never a prior SELECT - the same
proven pattern used since Phase B2's UsageTrackingService and every subsequent
phase's own balance mutations.

Cart Integration:
Cart.coupon_code (new column) - applied/removed via dedicated endpoints,
lightly validated at apply-time via the same eligibility engine.
CartService::totals()/CartResource surface a live, non-authoritative promotion
preview.

Checkout Integration:
Evaluates promotions after the shipping quote is computed (so free-shipping's
benefit is comparable in real minor units), before Order creation; folds the
result into discount_total_minor/line_discounts; records usage only when the
Order was genuinely just created.

Order Snapshot Behavior:
order_promotions preserves promotion_name_snapshot, promotion_type_snapshot,
coupon_code_snapshot, and discount_amount_minor at the moment of order creation -
editing or deleting the live Promotion afterward never changes what an existing
Order shows (tested explicitly).

Shipping Integration:
No duplicate shipping-rate logic. ShippingRateService::quote() (Phase B8) is
called completely unchanged; the promotion engine only decides whether to zero
the already-computed shipping cost.

Payment Integration:
No duplicate payment logic. PaymentService::createForOrder() (Phase B7) is
called completely unchanged; it reads Order.grand_total_minor, which already
has the discount folded in by the time Payment is created.

Cancellation/Refund Behavior:
No automatic usage restoration - documented, conservative default per Module
14's silence on this point.

Database/Migration Summary:
6 new/modified migrations: promotions, promotion_targets, coupons,
promotion_usages, order_promotions (all new tables), carts.coupon_code
(additive column). No existing table's existing column altered, renamed, or
removed. No destructive operation performed.

API Summary:
GET/POST/PUT /api/v1/promotions[/{id}], GET /api/v1/promotions/{id}/coupons,
POST /api/v1/coupons, DELETE /api/v1/coupons/{id} (all staff), POST/DELETE
/api/v1/cart/coupon (guest/customer).

Admin UI Summary:
Not built - matches Phase B6/B7/B8's own precedent of no frontend built in
these backend-focused phases.

Storefront UI Summary:
Not built - same precedent.

Events/Outbox Summary:
No new outbox events were introduced in B9. Promotion usage recording
(promotion_usages + order_promotions) happens transactionally alongside the
existing order.created event (Phase B5, unchanged) rather than emitting a
separate CouponApplied/PromotionRedeemed event - documented simplification,
since no consumer exists yet for a dedicated promotion event (mirrors B7/B8's
identical "no Notification model exists yet" deferral reasoning).

Audit Summary:
PromotionUsage is append-only and doubles as the audit trail for successful
redemptions (promotion, coupon, customer, order, discount amount, timestamp).
Administrative promotion/coupon create and update operations go through the
standard staff-authorization + validation path; no dedicated separate audit
log entry is written for admin CRUD actions in B9 (documented limitation, not
silently assumed present).

Security Review:
Performed (docs/security/b9-security-review.md) - this milestone's full 30-item
checklist reviewed end-to-end, plus a B0-B8 regression confirmation. 2
design-time issues found and fixed. 2 known limitations documented (no
coupon-specific rate limiting; customer-specific promotions not implemented at
all, so customer-impersonation risk is satisfied by construction rather than a
dedicated test).

Tests Added:
34 new test methods across 6 Feature test files:
- tests/Feature/Promotions/PromotionEligibilityEngineTest.php - 13 methods
- tests/Feature/Promotions/CouponTest.php - 5 methods
- tests/Feature/Promotions/CheckoutPromotionIntegrationTest.php - 6 methods
- tests/Feature/Promotions/PromotionUsageConcurrencyTest.php - 1 method
- tests/Feature/Promotions/PromotionTenantIsolationTest.php - 4 methods
- tests/Feature/Promotions/PromotionAdminTest.php - 5 methods
Plus 2 new model factories (Promotion, Coupon). Combined with all carried-
forward B0-B8 tests: 297 test methods total across the whole suite (verified by
direct grep count, not estimated).

Tests Actually Executed:
NONE. No PHP, Composer, MySQL, or Redis runtime is available in this Claude App
sandbox.

Tests Not Executed:
All 297 test methods, including all 34 new to this milestone. The concurrency
test (PromotionUsageConcurrencyTest) is explicitly a sequential simulation, not
genuine parallel load - flagged as the highest-priority scenario to verify for
real once a runtime is available, consistent with every prior phase's identical
precedent.

Static Inspections Performed (EXECUTED vs INSPECTED vs NOT EXECUTED - nothing
below was EXECUTED):
- Source inspection of every new/modified file against Module 14's
  requirements.
- A lightweight Node.js-based brace/parenthesis balance check across all new/
  modified PHP files - no mismatches found.
- Route inspection: confirmed staff promotion/coupon routes are inside the
  staff.principal-guarded group and cart-coupon routes are inside the
  customer.optional (guest-accessible) group.
- Migration inspection: confirmed foreign keys, unique constraints
  (store_id+code_normalized on coupons; order_id+promotion_id on promotion_
  usages), and index coverage for the query patterns
  PromotionEligibilityEngine/PromotionService actually use.
- Cross-reference check: confirmed OrderService::createOrder()'s two new
  parameters, InventoryService/PaymentService/ShippingRateService's untouched
  methods are called with exactly the signatures those classes expose, with
  zero changes to any prior phase's existing method bodies.
- Regression re-check of OrderStateMachine/ShipmentStateMachine (0
  promotion-related references, confirming discounts never influence Order or
  Shipment status transitions).

Known Limitations:
- Nothing in this milestone has been executed against a real runtime.
- No dedicated rate limit on coupon application.
- No dedicated audit log entries for admin promotion/coupon CRUD actions beyond
  standard authorization/validation.
- Customer-specific/group/segment promotions are not implemented (Module 10
  infrastructure doesn't exist yet).

Deferred Functionality:
Buy X Get Y, tiered quantity discounts, customer group/segment/first-order/
repeat-customer promotions, payment-method discounts, collection discounts (no
Collection entity exists), bulk/generated coupon codes, scheduled/recurring
promotions and flash sales, product/customer purchase limits, promotion
preview/simulation and version history, loyalty/referral/B2B/multi-currency
integration, admin/storefront UI. Full list with rationale in
docs/development/b9-inspection-findings.md.

Files Changed:
New: app/Domain/Promotions/ (Models: Promotion, PromotionTarget, Coupon,
PromotionUsage, OrderPromotion, PromotionType, PromotionTargetScope,
PromotionStatus; Services: PromotionEligibilityEngine, PromotionEvaluationResult,
PromotionService; Policies: PromotionPolicy; Http/{Controllers: Promotion
Controller, CouponController; Requests: SavePromotionRequest, SaveCouponRequest;
Resources: PromotionResource, CouponResource}; Exceptions: 2 classes). New: 6
migrations, 2 factories (Promotion, Coupon), 6 test files. Modified: OrderService
(+discount_total_minor/line_discounts params, +findExistingOrderByIdempotencyKey,
additive), CartService (+applyCoupon/removeCoupon/promotionPreview), Cart model
(+coupon_code fillable), CartResource (+coupon_code/promotion fields),
CartController (+applyCoupon/removeCoupon endpoints), CheckoutService
(+promotion integration + idempotent-replay fix), AppServiceProvider
(+PromotionPolicy), PermissionSeeder (+promotions.view/manage), PackageSeeder
(+promotions.basic for all 3 tiers), StoreObserver (+promotions permissions for
Manager), routes/api_v1.php and routes/api_v1_customer.php (+promotion/coupon
routes).

Git Status:
Verified by direct execution (git status) before this checkpoint was written:
all files listed above are new/modified/staged relative to the previous commit
(3184400 / 3259813). No files outside the Promotions domain, Cart/Checkout/
Order integration points, and documentation were touched.

Git Commit Status:
A commit for this milestone's work follows immediately after this checkpoint;
the real, executed commit hash is recorded via a follow-up correction commit
immediately after, same pattern used for every prior phase's checkpoint.

Recommended Next Milestone:
Phase B10 - per the approved milestone map, the next natural dependency is
Module 15 (Marketing & Customer Engagement) or Module 21 (Notifications &
Communication). Recommend Module 21 next: B7 deferred payment notifications, B8
deferred shipment notifications, and B9 deferred promotion-redemption
notifications, all explicitly for the identical reason - "no Notification model
exists yet." Building Module 21 next would let a single milestone retroactively
wire all three phases' already-emitted outbox events (payment.initiated,
payment.refunded, shipment.created, order.created) into real customer
notifications, rather than each subsequent phase re-deferring the same gap.
Phase B10's own Step 1 should inspect every outbox event type emitted since
Phase B0 before designing the notification consumer.
