# Phase B5 — Focused Security Review

Static/design-level review only — **NOT EXECUTED — DEFERRED TO VS CODE RUNTIME
VERIFICATION**.

## Regression Check (Step 19) — B0-B4 Capabilities Confirmed Intact

Verified by inspection, not modified this milestone: `BelongsToTenant::store()` (B4
critical fix), tenant context resolution, authentication, authorization/Policies,
roles/permissions, subscriptions/entitlements, catalog (Product/Category/Brand/
Attribute), Warehouse/Inventory/StockMovement/StockReservation, idempotency pattern,
outbox pattern, low-stock events, API v1 route structure, frontend foundation. No B5
change touches any of these files' core logic — only additive registrations (new
Policies in `AppServiceProvider`, new permissions in `PermissionSeeder`, new roles in
`StoreObserver`) were made.

## Standard B5 Checklist (this milestone's 25-item Step 15 list)

| # | Item | Finding | Status |
|---|---|---|---|
| 1 | Cross-tenant order access | `Order` uses `BelongsToTenant`; 404 on cross-tenant ID (3 dedicated tests). | Reviewed — OK |
| 2 | IDOR/order enumeration | Route-model binding + global scope; sequential `order_number` is NOT a security boundary by design (ADR-001 ownership check is) — see architecture doc. | Reviewed — OK |
| 3 | Customer order isolation | Deferred — no customer-facing auth boundary exists yet (B5 is staff-only); flagged for Module 10/11, not silently assumed safe for a surface that doesn't exist. | N/A this milestone, documented |
| 4 | Staff authorization | `OrderPolicy` (view/create/cancel) — Gate::authorize() call verified present in every `OrderController` method. | Reviewed — OK |
| 5 | Super Admin boundary | No new Super Admin surface added or needed (order management is store-scoped). | N/A this milestone |
| 6 | Status tampering | `order.status` has no direct-write path from any controller — only `OrderService::transitionTo()`, which always validates via `OrderStateMachine` first. `Order`'s fillable list includes `status` only for the single initial `create()` call. | Reviewed — OK |
| 7 | Price tampering | `CreateOrderRequest` has no price/total field at all; `resolveAndPriceItem()` is the sole price source. Tested explicitly. | Reviewed — OK |
| 8 | Quantity abuse | `quantity` is the one client-supplied value trusted verbatim, but it is bounded by `InventoryService::reserve()`'s atomic guard — no quantity can result in reserving more than available stock. | Reviewed — OK |
| 9 | Discount/totals tampering | No discount/tax/shipping engine exists yet to tamper with; those columns are server-set to `0` unconditionally in B5. | N/A this milestone |
| 10 | Duplicate checkout/order submission | Idempotency key, unique per store, checked first. Tested. | Reviewed — OK |
| 11 | Idempotency-key abuse (reuse across unrelated orders) | The unique constraint is `(store_id, idempotency_key)` — a key can only ever map to ONE order per store; reusing a key with genuinely different item data still returns the FIRST order created under that key (standard idempotency semantics — the key, not the payload, is authoritative), which is documented behavior, not a bypass. | Reviewed — OK, documented semantics |
| 12 | Race conditions | Covered by inheriting B4's atomic reservation guarantee — no new race surface introduced. | Reviewed — OK |
| 13 | Inventory overselling | Same atomic guard as B4; `allow_overselling` per-store setting still respected (unchanged). | Reviewed — OK |
| 14 | Reservation abuse | Reservations created by `OrderService` are always tagged `reference_type='order'` + the real `order.id` — no code path lets a client create an order-tagged reservation without a corresponding, transactionally-consistent Order row. | Reviewed — OK |
| 15 | Cancellation abuse | State-machine-gated; re-cancellation of an already-cancelled order rejected (tested); cancellation requires `orders.cancel` permission or Owner. | Reviewed — OK |
| 16 | Audit log integrity | `OrderTimelineEvent` is append-only (no `update()`/`delete()` call exists against this model anywhere — verified by inspection), same convention as B4's `StockMovement`. | Reviewed — OK |
| 17 | Outbox tenant context | `order.created`/`order.cancelled` events carry `order_id` (their own `store_id` is set by `RecordsOutboxEvents` per ADR-004, unchanged); a consumer re-resolves tenant context from the event's own `store_id`, never ambient state. | Reviewed — OK |
| 18 | API validation | `CreateOrderRequest`/`CancelOrderRequest` — explicit rule sets, no blanket-trust fields. | Reviewed — OK |
| 19 | Mass assignment | `Order`/`OrderItem`/`Customer` all use explicit `$fillable`; `store_id` never client-settable (`BelongsToTenant`'s `creating` hook only). | Reviewed — OK |
| 20 | Sensitive data exposure | `OrderResource` exposes guest name only `when($this->isGuestOrder())`; no cost-price field anywhere in the Order domain (Order doesn't reference cost at all — that's `Inventory`'s concern, already permission-gated in Phase B3/B4). | Reviewed — OK |
| 21 | Error leakage | Controller catches every domain exception explicitly and returns a structured message + code — no raw exception message or stack trace is ever returned to the client. | Reviewed — OK |
| 22 | Rate limiting | Not added specifically for order creation in B5 — inherits the platform's default `api` middleware group throttle (same as every other B1-B4 endpoint); a dedicated, lower rate limit for order creation (Module 09 §82's fraud/abuse foundation) is explicitly deferred, not silently assumed sufficient. | Documented limitation, deferred |
| 23 | Cache isolation | No order data is cached in B5 (documented as deferred — Module 09 §77 "Order Cache" has no consumer yet). | N/A this milestone |
| 24 | Job isolation | No background job was introduced for B5 (order creation is fully synchronous within the request); the outbox dispatcher (existing, unchanged) already re-resolves tenant context per-event, per ADR-001 Layer 6. | Reviewed — OK |
| 25 | Event replay behavior | `order.created`/`order.cancelled` use deterministic idempotency keys (`order:{id}:created` / `order:{id}:cancelled`) — a replayed outbox event is a safe no-op for any consumer that itself respects idempotency (the same contract every other outbox event in this codebase already requires of its consumers, per ADR-004). | Reviewed — OK |

## Issues Found and Fixed Within B5 Scope

1. **`OrderNumberGenerator`'s MySQL sequence idiom would have been unreliable on the
   very first order for a store** — `LAST_INSERT_ID(expr)` is only evaluated on the
   `ON DUPLICATE KEY UPDATE` branch, and without a pre-existing row the first INSERT
   would take the fresh-row branch instead, leaving `LAST_INSERT_ID()` unset for this
   table (it has no AUTO_INCREMENT column). **Fixed** by having `StoreObserver`
   pre-seed the sequence row (at `next_number = 0`) for every new store, so the
   generator's `INSERT ... ON DUPLICATE KEY UPDATE` always takes the reliable UPDATE
   branch — caught during design review before any test was written against it.
2. **`OrderController::store()`'s `wasRecentlyCreated`-based 200-vs-201 status code
   would have always reported 200`** — the original `OrderService::createOrder()`
   returned `$order->fresh(['items'])`, and `fresh()` constructs a brand-new model
   instance via a new query, which does not carry over the `wasRecentlyCreated` flag
   from the instance that was actually just inserted. **Fixed** by using `load()`
   (which eager-loads onto the SAME instance) instead of `fresh()` — caught during
   implementation, before any test was run against the (never-yet-executed) behavior.

## Issues Documented for Later Modules (Outside B5 Scope)

1. Customer-facing order access/isolation — no such surface exists yet (Module 10/11).
2. Dedicated, lower rate limits for order creation (fraud/abuse foundation, §82-83) —
   inherits the platform default for now.
3. Order cache (§77) and search index (§76) — no consumer exists yet.

None of the "found and fixed" items required deleting or resetting existing B0-B4
work. No destructive database operation was performed (all 5 new migrations are
new-table only).
