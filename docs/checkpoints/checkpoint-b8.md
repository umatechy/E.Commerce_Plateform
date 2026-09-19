============================================================
PHASE B8 CHECKPOINT
============================================================

Phase:
Development Phase B

Module:
B8 — Shipping & Delivery Management (Module 13)

Implementation Summary:
Implemented a tenant-safe, extensible shipping domain integrated with Orders,
Payments, and Inventory without duplicating any of their business logic: shipping
zones with deterministic priority matching, 6 shipping method types with
server-authoritative rate calculation, a Shipment/ShipmentItem/ShipmentTrackingEvent
domain with its own state machine, a carrier abstraction with three adapters (Store
Pickup, Local Delivery, and a test-mode-only Mock Courier), signature-verified
idempotent carrier webhooks, and the resolution of Phase B4's long-deferred
inventory reservation-to-commitment step. Checkout (Phase B6/B7) now calculates and
charges for shipping; Shipment creation itself remains a separate, later,
staff-initiated fulfillment action. Runtime execution remains deferred to VS Code —
nothing in this milestone has been executed against a real PHP/MySQL runtime, and
no live carrier credentials exist in this environment.

Bugs Found and Fixed (design-time, caught before being left in the codebase):
1. ShipmentStateMachine's initial transition map did not allow draft ->
   label_created, and the very first shipment status write bypassed the state
   machine entirely via a direct model update rather than the validated
   transitionTo() method. Both fixed before being left in the codebase.
2. OrderItemResource (Phase B5) exposed no identifier for an OrderItem at all -
   staff would have had no way to reference "which line item" when creating a
   Shipment via the API. Fixed by adding the internal integer id (reviewed and
   accepted as safe - staff-only, parent-Order-scoped).
3. Phase B6/B7's own CheckoutTest/CheckoutConcurrencyTest/
   CheckoutPaymentIntegrationTest would have broken against B8's new required
   shipping.basic entitlement and shipping_method_id (for non-digital carts) -
   fixed as part of this milestone's mandatory regression review, the same
   documented, intentional API-contract-evolution pattern Phase B7 itself used
   when it updated Phase B6's tests.
Also applied, correctly, from the start (not a fix but a deliberate avoidance of a
known prior mistake): the shipment_webhook_secret migration backfill used a
per-row loop from the very first draft, avoiding the exact bulk-UPDATE-with-
Str::random() defect Phase B7 found and fixed for payment_webhook_secret.

Architectural Decisions:
- Shipment creation happens AFTER Order creation, as a separate staff action
  (Module 13's own Sec29 worked pickup flow), distinct from Payment (B7), which is
  created immediately at checkout.
- Fulfillment is allowed once Order.payment_status is Paid/PartiallyPaid/
  Authorized, OR the payment method is Cash on Delivery with status still Pending
  (COD delivers first, collects cash later).
- InventoryService::fulfillReservation() (new, additive method) finally resolves
  B4's own documented deferred "reservation to commitment" extension point -
  atomically decrements on_hand and reserved together, records a SaleOut movement,
  and marks the reservation Converted, supporting partial fulfillment.
- No persisted ShippingQuote entity - a quote is computed live, server-side, in
  the same request that creates the Order, matching B6/B7's "one-shot checkout"
  precedent.

Shipping Domain:
ShippingZone (geographic, deterministic priority), ShippingMethod (6 types),
ShippingRate (per zone+method), PickupLocation, Shipment + ShipmentItem
(quantity-tracked, never exceeding ordered quantity), ShipmentTrackingEvent
(append-only), ShipmentWebhookEvent (not tenant-scoped, mirrors Phase B7's
PaymentWebhookEvent).

Shipping Methods:
flat_rate, free (order-value threshold), weight_based (linear per-kg), price_based
(single threshold surcharge - documented simplification), store_pickup,
local_delivery. Every store gets a working default (catch-all zone + free Store
Pickup + rate) seeded by StoreObserver at creation, same precedent as the default
Warehouse/Roles.

Shipping Zones/Rates:
Zone priority is deterministic: postal_code > city > province > country > default,
computed via a specificity score, never random selection. Rates are one row per
(zone, method); cost is always server-calculated by ShippingRateService, never
client-supplied.

Address Handling:
Reuses Order's existing shipping_address_snapshot/billing_address_snapshot (Phase
B5/B6, unchanged) as the historical record. No duplicate customer-address system
was created.

Shipment Summary:
Shipment is a separate entity from Order (Module 13 Sec48) - no unique(order_id)
constraint, since one Order may contain multiple Shipments. "Dumb" model;
ShipmentService is the sole writer of status.

Tracking Summary:
ShipmentTrackingEvent is append-only, recording status, carrier event code,
description, location, source (manual/webhook/system), and actor where applicable.
Never overwritten.

Carrier Abstraction:
CarrierGatewayContract (providerName/createShipment/verifyWebhookSignature/
translateWebhookPayload) mirrors Phase B7's PaymentGatewayContract exactly.
CarrierResolver is the single provider-to-adapter mapping point.

Fulfillment/Inventory Integration:
No new inventory logic beyond the single additive InventoryService method
described above. ShipmentService never touches inventory columns directly.

Payment/Shipping Relationship:
Payment and shipping state remain fully separate (Non-Negotiable Rule #26) -
ShipmentService reads Order.payment_status/Payment.method to gate fulfillment but
never writes to either; PaymentService is entirely unaware Shipping exists.

Idempotency Summary:
Shipment creation: unique(store_id, idempotency_key), checked first (mirrors
Order/Payment pattern exactly). Inventory fulfillment: reuses
InventoryService's existing idempotency-key mechanism. Webhook processing:
unique(provider, external_event_id), mirroring Phase B7 exactly.

Webhook Summary:
No Sanctum/staff/customer middleware on the shipment webhook route
(Non-Negotiable Rule #13). Dedup first, then resolve Shipment from the payload's
own tracking_number (never the URL segment), then signature-verify against that
shipment's own store secret, then process. Always responds 200 regardless of
internal outcome.

Database/Migration Summary:
10 new/modified migrations: stores.shipment_webhook_secret (additive, backfilled
correctly per-row from the start), shipping_zones, shipping_methods,
shipping_rates, pickup_locations, shipments, shipment_items,
shipment_tracking_events, shipment_webhook_events (all new tables). No existing
table's existing column altered, renamed, or removed. No destructive operation
performed.

API Summary:
GET/POST /api/v1/shipments[/{id}], POST /api/v1/shipments/{id}/status, GET
/api/v1/shipments/{id}/tracking-events, GET/POST /api/v1/shipping/zones,methods,
POST /rates (all staff), GET /api/v1/shipping/quote (guest/customer, live), GET
/api/v1/customer/orders/{id}/shipments (customer, ownership-checked), POST
/api/v1/shipment-webhooks/{provider} (no auth). POST /api/v1/checkout (B6/B7)
extended: now accepts shipping_method_id, required for non-digital-only carts.

UI Changes:
None - matches Phase B6/B7's own precedent of no storefront/admin frontend in this
pass.

Notifications Integration:
Outbox event (shipment.created) wired for a future consumer; no Notification
model exists yet (Module 21 not built), exactly mirroring how Phase B7 deferred
payment notifications for the identical reason.

Security Review:
Performed (docs/security/b8-security-review.md) - this milestone's full 30-item
checklist reviewed end-to-end, plus a B0-B7 regression confirmation. 3 design-time
issues found and fixed. 3 known limitations documented (no webhook-specific rate
limit; no stuck-shipment reconciliation job; OrderItem uses an internal id rather
than a public_id for staff-facing reference).

Tests Added:
38 new test methods across 7 Feature test files:
- tests/Feature/Shipping/ShippingRateServiceTest.php - 8 methods
- tests/Feature/Shipping/ShipmentCreationTest.php - 9 methods
- tests/Feature/Shipping/ShipmentStateMachineTest.php - 6 methods
- tests/Feature/Shipping/ShipmentWebhookTest.php - 6 methods
- tests/Feature/Shipping/ShipmentTenantIsolationTest.php - 4 methods
- tests/Feature/Shipping/CheckoutShippingIntegrationTest.php - 4 methods
- tests/Feature/Shipping/ShipmentConcurrencyTest.php - 1 method
Plus 4 new model factories (ShippingZone, ShippingMethod, ShippingRate, Shipment)
and 1 new factory for an existing B5 model (OrderItemFactory, additive - OrderItem
gained HasFactory). 3 existing Phase B6/B7 test files updated for the new API
contract. Combined with all carried-forward B0-B7 tests: 263 test methods total
across the whole suite (verified by direct grep count, not estimated).

Tests Actually Executed:
NONE. No PHP, Composer, MySQL, or Redis runtime is available in this Claude App
sandbox.

Tests Not Executed:
All 263 test methods, including all 38 new to this milestone.

Live Carrier Testing Status:
DEFERRED. No live courier API credentials exist in this environment. No real HTTP
call was made to any external carrier at any point in this milestone.
MockCourierCarrier is an explicitly-labeled, deterministic test double - its
webhook signature tests use a locally-computed HMAC, never a real carrier's
signing key or endpoint. Real carrier integration remains a documented, deferred
item (see inspection findings "Scope Decision").

Static Inspections Performed (EXECUTED vs INSPECTED vs NOT EXECUTED vs DEFERRED -
nothing below was EXECUTED):
- Source inspection of every new/modified file against Module 13's requirements.
- A lightweight Node.js-based brace/parenthesis balance check across all new/
  modified PHP files - no mismatches found.
- Route inspection: confirmed the shipment webhook route carries no auth
  middleware and the staff shipment/config routes are inside the
  staff.principal-guarded group.
- Migration inspection: confirmed foreign keys, unique constraints
  (store_id+tracking_number and store_id+idempotency_key on shipments;
  provider+external_event_id on webhook events; zone_id+method_id on rates), and
  index coverage for the query patterns ShipmentController/ShipmentService
  actually use.
- Cross-reference check: confirmed InventoryService::fulfillReservation(),
  OrderService::syncFulfillmentStatus(), and PaymentService's untouched methods
  are called with exactly the signatures those classes expose, with zero changes
  to any of B4/B5/B7's existing method bodies.
- Regression re-check of Phase B6/B7's checkout test payloads and entitlement
  setup against the new API contract (see "Bugs Found and Fixed" #3).

Known Limitations:
- Nothing in this milestone has been executed against a real runtime.
- No live carrier integration exists (by design, per this milestone's explicit
  prohibition on fabricating live carrier responses).
- No dedicated rate limit on the shipment webhook endpoint.
- No reconciliation/polling job for shipments with no webhook activity.
- OrderItem's internal integer id is used as a staff-facing reference field.

Deferred Functionality:
Real courier API integration, shipping labels, ShippingClass/product-specific
rules, automatic split-shipment allocation, return/exchange shipping, dimensional
weight/packaging, same-day/express eligibility engines, cutoff time/holiday
calendar, shipping discounts/tax/subsidy (Module 14's territory), tracking
polling, shipping dashboard/analytics/reconciliation, configuration versioning,
customer notifications (Module 21 not built), storefront/admin UI. Full list with
rationale in docs/development/b8-inspection-findings.md.

Git Status:
Verified by direct execution (git status) before this checkpoint was written: all
files listed above are new/modified/staged relative to the previous commit
(66d66a5 / 6888a16). No files outside the Shipping domain, Checkout/Order/
Inventory integration points, and documentation were touched.

Git Commit Status:
Commit created: 3184400 — "Phase B8: Shipping & Delivery Management (Module 13)".
Verified by direct execution (git log --oneline after the commit): working tree
clean, history now shows six real commits: 5dcb815 (Phase B0-B5), b12ae2b (B5
checkpoint correction), 47d6a1c (Phase B6), 24b7bd3 (B6 checkpoint correction),
66d66a5 (Phase B7), 6888a16 (B7 checkpoint correction), 3184400 (this milestone).
No fabricated incremental history.

Recommended Next Milestone:
Phase B9 - per the approved milestone map, Module 14 (Discounts, Coupons &
Promotions) is the next natural dependency: both Order (discount_total_minor,
Phase B5) and Cart's totals() (Phase B6) have carried a hardcoded-zero discount
placeholder since their own respective phases, exactly mirroring how B7 found
payment_status and B8 found fulfillment_status/shipping_total_minor waiting for
them. Phase B9's own Step 1 should inspect OrderService's discount_total_minor
handling and CheckoutService's/CartService's totals() calculation before writing
any coupon/promotion code, since both already have the schema-ready integration
seam and B9 would extend them additively, the same pattern every phase since B5
has followed.
