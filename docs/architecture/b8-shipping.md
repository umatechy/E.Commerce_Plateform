# Phase B8 — Shipping & Delivery Management Architecture (Module 13)

See `docs/development/b8-inspection-findings.md` for the scope decision and four
architectural decisions (shipment creation timing, payment/fulfillment ordering,
inventory commit point, no persisted shipping quote).

## Entities

- **ShippingZone** — tenant-scoped, geographic (country/province/city/postal_code),
  deterministic priority via `specificity()` (postal_code=8 > city=4 > province=2 >
  country=1), falling back to `is_default`.
- **ShippingMethod** — 6 types (Module 13 §15's own list): flat_rate, free,
  weight_based, price_based, store_pickup, local_delivery.
- **ShippingRate** — one row per (zone, method); `base_cost_minor` +
  `per_unit_cost_minor`/`unit_threshold` for weight/price-based linear calculation
  (a documented simplification of a full tiered-rate table).
- **PickupLocation** — minimal, for `store_pickup`.
- **Shipment** + **ShipmentItem** — "dumb" like every other core-state model;
  `ShipmentService` is the only writer. No `unique(order_id)` — Module 13 §48
  explicitly allows multiple shipments per order.
- **ShipmentTrackingEvent** — append-only (Module 13 §52's explicit rule).
- **ShipmentWebhookEvent** — deliberately NOT tenant-scoped, mirroring Phase B7's
  `PaymentWebhookEvent` exactly (a webhook arrives before tenant identity is
  trusted).

## Shipment Creation Timing (Distinct from B7's Payment Timing)

Payment (B7) is created immediately at checkout. Shipment (B8) is **not** —
Module 13 §29's own worked flow puts fulfillment AFTER order creation, as a
separate staff action. `CheckoutService` only calculates and charges for shipping;
`ShipmentService::createShipment()` is called later, by staff, once picking/packing
is ready.

## Server-Authoritative Shipping Cost

`ShippingRateService` is the ONLY place shipping cost is calculated.
`CheckoutService` calls `quote()` (throwing `DestinationNotServiceableException` if
no zone/rate matches, or the client's requested method isn't active) and passes the
result into `OrderService::createOrder()`'s new, additive `shipping_total_minor`
parameter — `grand_total_minor = subtotal_minor + shipping_total_minor`. The client
may REQUEST a `shipping_method_id`; it never supplies a price (Final Rule #4).

Digital-only carts (every item's `Product.type` is `Digital` or `Service` — Phase
B3's existing `ProductType`, no new column added) skip the shipping requirement
entirely (Final Rule #8).

## Inventory Commit Point — Resolves B4's Deferred Item

`InventoryService::fulfillReservation()` (new, additive method) is called by
`ShipmentService` when a `ShipmentItem` is created. This is the exact extension
point Phase B4's own documentation named as deferred ("actual commit/deduction is
deferred to whichever future module introduces a real commit step"):

1. Atomically decrements `on_hand` AND `reserved` together (one conditional
   `UPDATE`, guarded by both having enough — B4's established philosophy, not a new
   mechanism).
2. Records a `StockMovementType::SaleOut` movement (a case that existed since B4
   but was never triggered until now).
3. Reduces the reservation's own `quantity`; when it reaches 0, marks it
   `Converted` (a `ReservationStatus` case that also existed since B4 but was never
   reached until now).

Supports **partial fulfillment** — a 5-unit reservation may be fulfilled 3 now, 2
later across a second shipment.

## Payment/Fulfillment Ordering

`ShipmentService::assertPayableStateAllowsFulfillment()` — the ONE place this rule
is enforced. Allowed: `Order.payment_status` is `Paid`/`PartiallyPaid`/`Authorized`,
OR the Payment's method is `CashOnDelivery` with status still `Pending` (COD
delivers first, collects cash later — Module 12 §15's own flow). Anything else
(e.g. an online payment still `RequiresAction`) blocks fulfillment with a 422.

## Shipment Quantity Validation (Module 13 §11)

`ShipmentService::assertQuantityWithinRemaining()` sums every PRIOR shipment's
quantity for the same `OrderItem` and rejects a request that would exceed the
originally ordered quantity — enforced at the application layer (a database
constraint cannot express "sum across rows must not exceed a value on a different
table").

## Carrier Abstraction

`CarrierGatewayContract` (`providerName()`, `createShipment()`,
`verifyWebhookSignature()`, `translateWebhookPayload()`) — mirrors Phase B7's
`PaymentGatewayContract` exactly. `CarrierResolver` is the single provider-name-to-
adapter mapping point.

### Adapters Implemented

- **StorePickupCarrier** / **LocalDeliveryCarrier** — no external call; status
  progresses only via staff action (`ShipmentController::updateStatus()`), never a
  webhook (`verifyWebhookSignature()` returns `false` unconditionally).
- **MockCourierCarrier** — a TEST-MODE-ONLY stand-in for a real courier's create-
  shipment + webhook-tracking shape. No live HTTP call to any external carrier is
  ever made. Same HMAC-SHA256 signature scheme as Phase B7's `MockRedirectGateway`,
  keyed by a SEPARATE `shipment_webhook_secret` (different external party, different
  rotation lifecycle from Payment's `payment_webhook_secret`).

## Webhook Architecture (Module 13 §53, mirrors Phase B7 exactly)

`ShipmentWebhookController` — no Sanctum, no `staff.principal`/`customer.principal`.
Trust flow: dedup on `(provider, external_event_id)` first → resolve `Shipment`
from the payload's own `tracking_number` (the URL's `{provider}` segment is routing
only) → verify signature via that shipment's OWN store's `shipment_webhook_secret`
→ only then process. Always responds `200` regardless of internal outcome.

## API Endpoints Added in B8

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET/POST | `/api/v1/shipments[/{id}]` | staff | |
| POST | `/api/v1/shipments/{id}/status` | staff (`shipments.fulfill`) | |
| GET | `/api/v1/shipments/{id}/tracking-events` | staff | |
| GET/POST | `/api/v1/shipping/zones`, `/methods`, POST `/rates` | staff (`shipping_config.manage`) | |
| GET | `/api/v1/shipping/quote` | optional (guest/customer) | live, unpersisted |
| GET | `/api/v1/customer/orders/{id}/shipments` | customer (ownership check) | Module 13 §74 |
| POST | `/api/v1/shipment-webhooks/{provider}` | none (signature) | |
| POST | `/api/v1/checkout` | optional (B6/B7, extended) | now also accepts `shipping_method_id`; digital-only carts skip it |

## UI

Not built in B8, matching B6/B7's own precedent — no storefront frontend exists yet
in this pass. Deferred, not silently dropped.

## Notifications

No `Notification` model exists yet (Module 21 not built) — outbox events
(`shipment.created`) are wired for a future consumer, exactly mirroring how B7
deferred payment notifications for the identical reason.

## Deferred (see inspection findings for the full, explicit list)

Real courier API integration, shipping labels, `ShippingClass`/product-specific
rules, automatic split-shipment allocation, return/exchange shipping, dimensional
weight/packaging, same-day/express eligibility engines, cutoff time/holiday
calendar, shipping discounts/tax/subsidy, tracking polling, shipping dashboard/
analytics/reconciliation, configuration versioning, customer notifications.
