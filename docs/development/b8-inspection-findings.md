# Phase B8 — Step 1: Inspection + Scope Decision (Shipping & Delivery: Module 13)

## Inspection of Existing Code

- `Order.shipping_total_minor` (B5) — column already exists, hardcoded to `0` by
  `OrderService::createOrder()` since no Shipping module existed yet. **B8 extends
  `createOrder()` additively** (new optional `shipping_total_minor` key in
  `$orderData`, defaulting to `0` — fully backward compatible with every existing
  caller) so `grand_total_minor = subtotal_minor + shipping_total_minor`.
- `Order.shipping_address_snapshot`/`billing_address_snapshot` (B5/B6) — already
  JSON snapshot columns; reused unchanged as the historical shipping-address record
  Module 13 §73 requires ("historical order/shipping address snapshots must not
  change retroactively").
- `Order.fulfillment_status` (B5) — already exists, defaulted to `Unfulfilled`,
  never written to by any code yet. **B8's `ShipmentService` becomes its first real
  writer**, via a new, additive `OrderService::syncFulfillmentStatus()` method
  (mirrors Phase B7's `syncPaymentStatus()` pattern exactly).
- `InventoryService::reserve()`/`release()` (B4) — reused unchanged. **Critical
  finding**: B4's own documentation explicitly deferred "converting a reservation
  into an actual stock deduction" to "whichever future module introduces a real
  commit step" — **B8 is that module**. A new method,
  `InventoryService::fulfillReservation()`, is added (additive, not a
  modification of `reserve()`/`release()`) that atomically deducts `on_hand`,
  reduces `reserved`, records a `StockMovementType::SaleOut` movement (this enum
  case has existed since Phase B4 but was never triggered until now), and marks the
  reservation `Converted` (a `ReservationStatus` case that has also existed since
  Phase B4 but was never reached until now).
- `PaymentStatus`/`PaymentService` (B7) — reused unchanged for the fulfillment/
  payment ordering decision (see below). No Payment code is modified.
- `ProductType` (B3) — already distinguishes `Digital`/`Service` from
  `Simple`/`Variable`. **Reused directly** as the "does this item require physical
  shipping" signal (Module 13 Final Rule #8) — no new `requires_shipping` column
  was added to `Product`, avoiding a duplicate signal for the same concept.
- `CheckoutService`/`CheckoutRequest` (B6, extended in B7) — extended again,
  additively: an optional `shipping_method_id` is accepted; when the cart is not
  digital-only, a shipping quote is computed server-side and its cost flows into
  `OrderService::createOrder()`'s new `shipping_total_minor` parameter.
- `EnsureStaffPrincipal`/`EnsureCustomerPrincipal`, `BaseTenantPolicy`,
  `RecordsOutboxEvents`, the webhook-signature pattern from B7 — all reused
  unchanged for Shipping's own staff routes, `ShipmentPolicy`, outbox events, and
  carrier webhook verification respectively.
- No regressions found in B0-B7 during inspection.

## Architectural Decision — Shipment Creation Timing (Distinct from B7's Payment Timing)

Module 13 §29's own worked pickup flow is explicit: **"Checkout → ... → Create
Order → Fulfillment → Ready for Pickup → ..."** — fulfillment (and therefore
Shipment creation) happens AFTER Order creation, as a separate STAFF action, unlike
Phase B7's Payment (created immediately during checkout). **Decision**: `Checkout`
only calculates and charges for shipping (server-authoritative cost baked into the
Order total) — it does NOT create a `Shipment` row. Staff create the `Shipment`
later via `ShipmentService::createShipment()`, once picking/packing is ready. This
correctly matches real-world fulfillment operations (an order is placed and paid
for; a warehouse team fulfills it hours or days later) and Module 13 §48's "Order
lifecycle remains controlled by Module 09; Shipment lifecycle is controlled by
Module 13" separation.

## Architectural Decision — Payment/Fulfillment Ordering (Step 17)

Module 13 §41 is explicit that Module 12 owns COD payment state and Module 13 owns
delivery serviceability — it does not mandate a universal "must be paid before
shipped" rule. **Decision, documented rather than invented**: `ShipmentService`
requires `Order.payment_status` to be one of `Paid`/`PartiallyPaid`/`Authorized`, OR
the Payment's method to be `CashOnDelivery` with status `Pending` (COD's entire
point is deliver-then-collect — Module 12 §15's flow explicitly ends with
"Fulfillment/Delivery" BEFORE "Cash Collected"). Any other combination (e.g. an
online payment still `RequiresAction`) blocks fulfillment. This is enforced in one
place (`ShipmentService::assertPayableStateAllowsFulfillment()`), not scattered
across controllers.

## Architectural Decision — Inventory Commit Point (Resolves B4's Deferred Item)

Reservation → Commitment finally happens here: `InventoryService::fulfillReservation()`
(new, additive method) is called by `ShipmentService` when a `ShipmentItem` is
created for a given quantity of an `OrderItem`. This is the exact extension point
Phase B4's own documentation named as deferred — implemented now, by the module that
actually needs it, using B4's existing atomic-update philosophy (a single
conditional `UPDATE` guarded by `on_hand >= quantity`), not a new concurrency
mechanism.

## Architectural Decision — No Persisted `ShippingQuote` Entity

Module 13 §82-83 describes a `ShippingQuote`/quote-expiry concept for a multi-step
checkout session. B6/B7 already established that this platform's Checkout is
synchronous/one-shot (no persisted `CheckoutSession`) — a shipping quote is
therefore computed on-demand, server-side, in the same request that creates the
Order, and is never separately persisted or subject to its own expiry clock. This
is the same "documented simplification for a one-shot checkout" reasoning B6 used
for its own Checkout Session decision.

## Scope Decision (Module 13 spans 108 sections and lists 30 potential entities in
§105 — the largest scope-control challenge yet; same discipline as B3-B7)

**B8 implements**: `ShippingZone` (with deterministic postal-code > city > province
> country > default precedence, Module 13 §9), `ShippingMethod` (flat_rate, free,
weight_based, price_based, store_pickup, local_delivery — §15's own list),
`ShippingRate` (per zone+method), `PickupLocation` (minimal, for the `store_pickup`
method), `Shipment` + `ShipmentItem` (quantity-tracked against `OrderItem`, never
exceeding ordered quantity) + `ShipmentTrackingEvent` (append-only), a shipment
state machine, a carrier abstraction (`CarrierGatewayContract`) with three adapters
(`StorePickupCarrier`, `LocalDeliveryCarrier` — both with no external calls — and a
test-mode-only `MockCourierCarrier` standing in for a real courier's webhook+
tracking shape, mirroring B7's `MockRedirectGateway` precedent exactly), signature-
verified idempotent carrier webhooks, server-authoritative shipping cost integrated
into `Order.grand_total_minor`, the inventory commit point described above, and
staff-facing + minimal customer-facing (tracking view) APIs.

**Explicitly deferred** (named so nothing is silently dropped, given the
exceptionally large §105 entity list):
- Real courier API integration (a specific Pakistani courier's actual SDK/API) —
  no live credentials exist; `MockCourierCarrier` stands in, exactly as B7's
  `MockRedirectGateway` did for payment gateways.
- Shipping labels (`ShipmentLabel`) — no real carrier to generate one from; the
  `Shipment` model has a nullable `label_url` column reserved for this, unpopulated.
- `ShippingClass`, product-specific/category-specific shipping rules (§21-22) — no
  numeric values are given by the specification; not invented.
- Split shipments as an automatic algorithm (§66) — the schema supports multiple
  `Shipment` rows per `Order` (no `unique(order_id)` constraint on `shipments`,
  unlike Payment's `unique(store_id, order_id)`), but no automatic warehouse-
  allocation/splitting logic decides how to split — a staff member creates each
  Shipment manually with the items/quantities they choose.
- Return shipping / `ShipmentReturn` / exchange shipping (§70-71) — Module 09's own
  return workflow (already deferred since Phase B5) is the natural owner.
- Dimensional weight, packaging, physical package dimensions (§57-58).
- Same-day/express delivery eligibility engines, cutoff time, processing time,
  holiday calendar (§31, §35-37) — `ShippingMethod` has type values for these but
  no time-based eligibility calculation.
- Shipping discounts/tax/subsidy (§60-63) — Module 14's territory, consistent with
  every prior milestone's discount/tax deferral.
- Tracking polling / scheduled jobs (§54) — only webhook-driven tracking updates
  are built; no scheduled polling job.
- Shipping dashboard, analytics, monitoring, reconciliation (§76, §95-98) — no
  consumer exists yet.
- `ShippingConfigurationVersion` (draft/publish versioning, §80) — configuration
  changes take effect immediately; no versioning/rollback.
- Customer notifications for shipment events (Module 21 does not exist yet) —
  outbox events are wired (Module 13 §84-86 explicitly, mirroring B7's Payment
  event precedent) but no `Notification` model exists to consume them into an
  actual email/SMS/push, exactly as B7 deferred payment notifications for the same
  reason.

None of these are abandoned — each is named so Phase B9+'s own Step 1 inspection
finds this documented list.

## Regression Fix Required in Phase B6/B7's Own Tests (Step 32 "Regression Review")

Adding a mandatory `shipping.basic` entitlement check and a `shipping_method_id`
requirement (for any non-digital-only cart) to `CheckoutService::checkout()` would
have broken every existing Phase B6/B7 checkout test that doesn't seed the new
entitlement or supply a shipping method — not a functional regression in the
underlying commerce flow, but a real, honest breakage of those tests' payloads
against the new (intentionally evolved) API contract, exactly the same class of
fix Phase B7 itself had to make to Phase B6's tests. Caught during this milestone's
mandatory regression review, before being left broken. `StoreObserver`'s new
default Store Pickup method (created for every store, including test-factory
stores) is what makes the fix straightforward: every affected test now also seeds
`shipping.basic`, resolves that store's auto-created Store Pickup
`ShippingMethod`/`ShippingRate` id, and passes it as `shipping_method_id` alongside
a minimal `shipping_address` (`country` is the only field the default catch-all
zone requires to match). `OrderService`, `InventoryService`, and `PaymentService`
themselves were not modified by this fix.

## Third Bug Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

`OrderItemResource` (Phase B5) exposed no identifier for an `OrderItem` at all —
staff would have had no way to reference "which line item" when creating a Shipment
via the API. Fixed by adding the internal integer `id` to `OrderItemResource`'s
output — reviewed and accepted because `OrderItem` has no `public_id` column, and
this field is only ever reached by staff already viewing an Order they are
authorized for (tenant isolation is enforced by the parent `Order`, not by this ID
being unguessable).
## Fourth Bug Found and Fixed During Review (Design-Time, Not Post-Hoc)

`ShipmentStateMachine`'s original transition map did not allow `label_created ->
picked_up` directly — only via an intermediate `pickup_requested` step. A real
courier's webhook (and `MockCourierCarrier`'s own `picked_up` event, built to
simulate exactly that) reports pickup directly without any prior local
"pickup requested" action necessarily having been recorded first, since requesting
a pickup is a store-initiated local step, not something every carrier's webhook
stream separately confirms before reporting the actual pickup. This would have
made the natural, realistic webhook flow throw
`InvalidShipmentStateTransitionException`. Fixed by adding `picked_up` as a direct,
explicitly-commented exception to `label_created`'s allowed transitions — caught
during this milestone's own review of the carrier adapters against the state
machine, before any test was run against the (never-yet-executed) behavior.

## Minor Additive Fix — OrderItem Gained a Factory

`OrderItem` (Phase B5) had no `HasFactory` trait and no corresponding
`OrderItemFactory` — B5's own tests never needed to create an `OrderItem` directly
(always via `OrderService::createOrder()`). B8's shipment tests need to construct an
`OrderItem` directly (to test quantity-exceeded validation against a specific
ordered quantity). Both were added additively — no existing B5 behavior changed.
