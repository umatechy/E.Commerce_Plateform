# Phase B8 — Focused Shipping Security Review

Static/design-level review only — **NOT EXECUTED — DEFERRED TO VS CODE RUNTIME
VERIFICATION**. No live carrier credentials exist in this environment; **live
carrier testing status: DEFERRED** (no real courier API call was ever made or
claimed — `MockCourierCarrier` is a deterministic, explicitly test-mode-only
double).

## Regression Check — B0-B7 Capabilities Confirmed Intact

Verified by direct grep/inspection: `BelongsToTenant::store()` present;
`OrderService::createOrder()` signature backward-compatible (new parameter is
optional, defaults to 0); `InventoryService::reserve()`/`release()` signatures
unchanged; `PaymentService::createForOrder()` unchanged;
`EnsureCustomerPrincipal`/`EnsureStaffPrincipal` present and unmodified;
`OrderStateMachine` untouched (0 shipping-related references — refunds and
shipments both correctly stay out of `Order.status`'s transition graph). Two
intentional, documented API-contract changes: `POST /api/v1/checkout` now requires
`shipping_method_id` for non-digital-only carts, and `shipping.basic` is now a
required entitlement — B6/B7's own checkout tests were updated accordingly (see
inspection findings "Regression Fix Required").

## Standard B8 Checklist (this milestone's 30-item Step 23 list)

| # | Item | Finding | Status |
|---|---|---|---|
| 1 | Cross-tenant shipment access | `Shipment`/`ShipmentItem` use `BelongsToTenant`; cross-tenant access → 404 (dedicated tests). | Reviewed — OK |
| 2 | Order/shipment IDOR | Shipments always resolved via `public_id` or route-model binding; `CreateShipmentRequest.order_id` is validated as the Order's `public_id`, never the internal id. | Reviewed — OK |
| 3 | Customer address exposure | `ShipmentResource` (shared by staff and customer views) contains no address field at all — only carrier/status/tracking/cost. Order's own address snapshot remains behind the existing staff-only Order endpoints. | Reviewed — OK |
| 4 | Customer authorization | `CustomerShipmentController` checks `order.customer_id === $customer->id` directly (identity match, same model as Cart/Wishlist, Phase B6) — never a route parameter trusted as ownership proof. | Reviewed — OK |
| 5 | Staff authorization | `ShipmentPolicy` (view/manage/create) and `ShippingConfigPolicy` (manage) — `Gate`/direct-policy calls verified present in every mutating controller method. | Reviewed — OK |
| 6 | Shipping-rate tampering | `ShippingRateService` is the sole cost source; `CheckoutService` never accepts a client cost. | Reviewed — OK |
| 7 | Shipping-method tampering | The client's requested `shipping_method_id` is validated for existence, active status, AND actual eligibility for the resolved zone (a rate must exist) — an ineligible/inactive method throws `DestinationNotServiceableException`, never silently substituting a different cost. | Reviewed — OK |
| 8 | Address manipulation | Destination fields (`country`/`province`/`city`/`postal_code`) only ever SELECT which zone/rate applies — they never themselves become a stored price or bypass any other check. | Reviewed — OK |
| 9 | Shipment quantity tampering | `assertQuantityWithinRemaining()` sums all prior shipments for the same `OrderItem`; over-shipment is rejected with the exact remaining amount, never silently clamped. Tested (including the two-staff concurrent case). | Reviewed — OK |
| 10 | Duplicate shipment creation | `idempotency_key`, unique per store, checked first (mirrors B5 Order / B7 Payment pattern exactly). Tested. | Reviewed — OK |
| 11 | Duplicate fulfillment | `InventoryService::fulfillReservation()` is itself idempotency-key-guarded (reuses the SAME mechanism as every other `InventoryService` mutation since B4) — a retried shipment-creation request cannot double-deduct stock. | Reviewed — OK |
| 12 | Tracking manipulation | Tracking status changes only via `ShipmentStateMachine`-validated transitions, from either an authorized staff action or a signature-verified webhook — never a raw field write. | Reviewed — OK |
| 13 | Carrier webhook spoofing | HMAC-SHA256 signature verification, constant-time compared, keyed by a per-store, never-exposed `shipment_webhook_secret` (a SEPARATE secret from Payment's — see architecture doc). Tested (valid/invalid cases). | Reviewed — OK |
| 14 | Webhook replay | `(provider, external_event_id)` uniqueness — dedup checked FIRST. Tested. | Reviewed — OK |
| 15 | Signature verification | Enforced unconditionally before any domain processing — an unsigned/badly-signed webhook never reaches `translateWebhookPayload()`. | Reviewed — OK |
| 16 | Unauthorized shipment status changes | `ShipmentController::updateStatus()` requires the `manage` Policy check (staff, `shipments.fulfill` permission or Owner) — never client-settable without it. | Reviewed — OK |
| 17 | Unauthorized cancellation | The state machine registers a `cancelled` transition from every non-terminal state, but no dedicated "cancel shipment" endpoint was built in B8 beyond the generic `updateStatus` (same permission gate applies) — no separate, weaker cancellation path exists. | Reviewed — OK |
| 18 | Inventory manipulation | `ShipmentService` never touches `inventories` columns directly — every mutation goes through `InventoryService::fulfillReservation()`. Verified by inspection (no `DB::table('inventories')` call anywhere in the Shipping domain). | Reviewed — OK |
| 19 | Payment/fulfillment bypass | `assertPayableStateAllowsFulfillment()` runs before ANY shipment/inventory side effect, inside the same method that creates the Shipment. Tested (blocked-pending-payment case). | Reviewed — OK |
| 20 | Sensitive address data exposure | Same as #3. | Reviewed — OK |
| 21 | Carrier credentials | No real carrier credential exists in B8 (Store Pickup/Local Delivery need none; Mock Courier's only "credential" is the webhook secret, never returned by any API). | Reviewed — OK |
| 22 | Secret leakage | `shipment_webhook_secret` is `$hidden` on `Store` (defense-in-depth) AND absent from `StoreResource`'s allow-list, exactly like Payment's secret. | Reviewed — OK |
| 23 | API enumeration | Public identifiers (`public_id`) used for Shipment/Order/Store; the one deliberate exception is `OrderItem`'s internal id (see inspection findings' third bug) — reviewed and accepted as staff-only, parent-Order-scoped. | Reviewed — OK, one documented exception |
| 24 | Rate limiting | Checkout retains its existing `throttle:10,1`; the shipment webhook endpoint has no dedicated rate limit of its own in B8 — same documented limitation as Phase B7's payment webhook. | Documented limitation |
| 25 | Cache isolation | No shipping data is cached in B8. | N/A this milestone |
| 26 | Queue/job tenant isolation | No background job introduced in B8 (webhook processing is synchronous within the request). | N/A this milestone |
| 27 | Event tenant context | `shipment.created` outbox event (ADR-004, reused unchanged) carries `shipment_id`/`order_id` — tenant context is the event's own `store_id`. | Reviewed — OK |
| 28 | Audit integrity | `ShipmentTrackingEvent` is append-only (no `update()`/`delete()` call exists anywhere against this model — verified by inspection), carrying `source` (`manual`/`webhook`/`system`) and `actor_id` for staff-initiated changes. | Reviewed — OK |
| 29 | Error leakage | Every controller catches domain exceptions explicitly and returns a structured message + code; the webhook controller always returns `200` regardless of internal failure reason (oracle-attack prevention, same as Phase B7). | Reviewed — OK |
| 30 | Historical address/shipping snapshot integrity | `Order.shipping_address_snapshot`/`billing_address_snapshot` (Phase B5/B6, unchanged) remain the historical record — B8 does not overwrite or re-derive them from any later, mutable source. | Reviewed — OK |

## Issues Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

1. The `shipment_webhook_secret` backfill migration correctly used a per-row loop
   from the start (the exact mistake Phase B7 made and fixed was not repeated here).
2. `ShipmentStateMachine`'s initial transition map did not allow `draft →
   label_created`, and the shipment's very first status write bypassed the state
   machine entirely (a direct `$shipment->update()` rather than the validated
   `transitionTo()`). Both fixed before being left in the codebase.
3. `OrderItemResource` exposed no identifier at all, which would have made it
   impossible for staff to reference a specific line item when creating a Shipment.
   Fixed by adding the internal `id` (reviewed and accepted as safe — see Checklist
   #23).

## Known Limitations (Documented, Not Hidden)

1. No dedicated rate limit on the shipment webhook endpoint (matches Payment's
   identical, already-documented limitation).
2. No reconciliation/polling job for shipments with no webhook activity past an
   expected delivery window (Module 13 §54/§97-98 — explicitly deferred).
3. `OrderItem`'s internal integer id is used as a staff-facing reference (not a
   `public_id`) — low risk, documented, not silently inconsistent.

None of the "found and fixed" items required deleting or resetting existing B0-B7
work. No destructive database operation was performed (all 10 new/modified
migrations in B8 are additive or new-table only).
