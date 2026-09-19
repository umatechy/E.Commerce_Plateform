# Phase B5 — Step 1: Inspection + Scope Decision (Orders: Module 09)

## Inspection of Existing Code

- `InventoryService::reserve()`/`release()` (B4) — already accept `referenceType`/
  `referenceId` parameters specifically so a future caller (this milestone) could tag
  a reservation as belonging to an order without any schema change. B5 uses these
  exactly as designed: `referenceType: 'order'`, `referenceId: $order->id`.
- `EntitlementService::assertCanUse()` (B2) — reused for order-count entitlement
  (`max_monthly_orders`, already seeded in B2's `PackageSeeder` for Basic — see
  below, no new numeric limit invented).
- `RecordsOutboxEvents` (B0/B2/B4) — reused for order events (Module 09 Final Rule
  #21, same "explicit reliable-delivery mandate" reasoning as B4's low-stock event).
- `BelongsToTenant::store()` (B4 critical fix) — confirmed present and used by every
  new B5 model. Verified NOT regressed.
- `BaseTenantPolicy` — reused verbatim for `OrderPolicy`.
- `Product::effectivePriceMinor()` / `ProductVariant::effectivePriceMinor()` (B3) —
  this is the ONE authoritative price source `OrderService` reads from; no client
  price is ever trusted (Module 09 §21/§107 rule #6).
- No regressions found in B0–B4 during inspection — Product/Variant/Warehouse/
  Inventory/Reservation/Idempotency/Outbox/Audit patterns are all reused unchanged.

## Scope Decision (Module 09 spans 108 sections — same discipline as B3/B4)

**B5 implements**: Order + OrderItem (with full historical snapshots per §10-11),
a minimal `Customer` model (the "concrete foundation" §9/§4 requires — Module 09
cannot express "Customer ID" or guest-vs-customer orders without SOME customer
concept existing, but full Customer Management, addresses, and storefront-facing
customer auth belong to Module 10/11, not built here), order number generation
(atomic per-store sequence), the order state machine (order/payment/fulfillment
statuses kept distinct per §16), server-authoritative pricing (subtotal only —
discount/tax/shipping are `0` placeholders ready for their owning modules),
inventory reservation-on-create and release-on-cancel (via B4's existing service,
no duplicate inventory logic), idempotent order creation, an append-only order
timeline, real outbox events, tenant isolation, entitlement integration
(`max_monthly_orders`), a staff-facing API, and a minimal admin UI.

**Explicitly deferred** (named so nothing is silently dropped):
- Payment processing, gateway integration, payment callback verification (§25-29) —
  Module 12's scope. `payment_status` column exists and defaults to `Unpaid`; no
  payment gateway code is written.
- Shipping/fulfillment execution, warehouse allocation, picking/packing/shipment
  (§55-62) — Module 13's scope. `fulfillment_status` column exists and defaults to
  `Unfulfilled`; no shipping code is written.
- Returns, refunds, exchanges, replacement orders (§45-54) — explicitly Module 09's
  OWN "Required Follow-Up Artifacts" list separates these into their own blueprints;
  `OrderStatus` includes the terminal-adjacent states (`ReturnRequested`, `Returned`,
  etc.) as schema-ready enum cases with no workflow behind them yet.
- Order editing/item modification/adjustments after confirmation (§38-40) — Module
  09's own Final Rule #11 ("confirmed orders must not be casually mutated") argues
  for a dedicated, carefully-controlled workflow later, not an ad-hoc PATCH endpoint
  now.
- Order notes, tags, priority (§31-33), search/filtering beyond basic listing
  (§34-35), export/import (§66-67), external order IDs/marketplace/POS (§68-71),
  webhooks (§80-81), fraud/COD-risk foundations (§82-83), analytics/reporting/
  dashboard (§89-91), data retention/deletion/anonymization (§86-88) — no consumer
  or dedicated workflow exists yet for any of these.
- Storefront-facing customer authentication and self-service order viewing — Module
  10 (Customer Management) and Module 11 (Cart/Checkout) own the customer-facing
  auth boundary entirely; B5's API is staff-facing only (Sanctum, same as every
  other B1-B4 endpoint). `Order.customer_id` is schema-ready for Module 10/11 to
  populate and query against once that boundary exists.
- Order Timeline vs Audit Log as two separate systems (§74 explicitly distinguishes
  them) — B5 implements ONE append-only `order_timeline_events` table serving both
  purposes (status transitions + actor/reason), documented as a deliberate
  simplification until Module 32's platform-wide audit log exists to take over the
  audit half.

None of these are abandoned — each is named so Phase B6+'s own Step 1 inspection
finds this documented list.
