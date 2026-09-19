# Phase B5 — Order Management Architecture (Module 09)

See `docs/development/b5-inspection-findings.md` for the explicit scope decision.

## Entities

- **Customer** — minimal foundation only (name/email/phone/optional `user_id`);
  full Customer Management is Module 10's scope.
- **Order** — Module 09 §4's field list, money as integer minor units + currency
  (ADR-003). Deliberately "dumb" like B4's `Inventory` — `OrderService` is the only
  writer of `status`/totals/reservations.
- **OrderItem** — full snapshot per §10-11: `product_name_snapshot`, `sku_snapshot`,
  `variant_snapshot`, `unit_price_minor` are captured once at creation and never
  re-derived from the live `Product`/`ProductVariant` row afterward.
- **OrderTimelineEvent** — append-only, serves both the "timeline" (§30) and, until
  Module 32's platform-wide audit log exists, the audit (§75) purpose — a documented
  simplification (see inspection findings).

## Three Distinct Identifiers (Module 09 §5)

| Identifier | Example | Purpose |
|---|---|---|
| Internal `id` | `4821` | Never exposed |
| `public_id` (ULID) | `01J...` | API addressing — consistent with every other resource on the platform |
| `order_number` | `ORD-000123` | Customer-facing, human-readable, store-scoped sequential |

## Order Number Generation — Concurrency Strategy

`OrderNumberGenerator` uses MySQL's `INSERT ... ON DUPLICATE KEY UPDATE next_number =
LAST_INSERT_ID(next_number + 1)` idiom against a pre-seeded per-store row
(`order_number_sequences`, seeded by `StoreObserver` at store creation — same
default-Warehouse/default-Roles precedent). This is a single atomic statement with no
read-then-write gap, consistent with every other atomic-SQL mechanism in this
codebase (B2's `UsageTrackingService`, B4's `InventoryService`). The row **must**
already exist before the first call, because `LAST_INSERT_ID(expr)` is only evaluated
on the `ON DUPLICATE KEY UPDATE` branch — this is documented in the class's own
docblock as a correctness requirement, not a style choice.

Sequential, guessable order numbers are **not** treated as a security boundary
(Module 09 §22) — the actual boundary is ADR-001 tenant/ownership enforcement, which
a sequential number cannot bypass.

## Order Creation Flow — B5's Documented Simplification of §19

Module 09 §19's full flow includes a Payment step this platform does not have yet
(Module 12). B5's `OrderService::createOrder()` therefore goes straight from
`PendingConfirmation` to `Confirmed` (matching the COD-flow precedent in §26: "Confirm
Order → Reserve/Commit Inventory"), with stock **reserved**, not yet deducted from
`on_hand`. Actual deduction/commitment is deferred to whichever future module
introduces a real "commit" step (Module 08 §48's Reserved-vs-Committed distinction) —
tested explicitly in `test_order_reserves_stock_but_does_not_deduct_on_hand`.

## Server-Authoritative Pricing (Module 09 §20-21, Final Rule #6)

`OrderService::resolveAndPriceItem()` is the ONLY place a line item's price is
determined — always from `Product::effectivePriceMinor()` /
`ProductVariant::effectivePriceMinor()`, never from client input. `CreateOrderRequest`
has no price/total field in its validation rules at all — there is nothing for a
client to submit, not merely a value that gets overwritten. Tested explicitly in
`test_order_total_is_computed_server_side_and_client_total_is_ignored`.

Discount/Tax/Shipping totals are `0` in B5 (no owning module exists yet) —
`grand_total_minor = subtotal_minor` for now, with the columns already in place for
Modules 12 (Payment)/13 (Shipping)/a future Promotions module to populate without a
schema change.

## Inventory Integration — No Duplicate Implementation

`OrderService` calls `InventoryService::reserve()`/`release()` (built in Phase B4)
directly — it contains no stock-quantity arithmetic of its own. Every line item's
reservation happens **inside the same `DB::transaction()`** as the Order/OrderItem
rows: if any line's reservation fails (insufficient stock), the whole transaction
rolls back, including the `Order` row itself and every prior successful reservation in
the same loop — there is no code path that leaves a created Order with a missing
reservation, or a reservation with no corresponding Order (Module 09 Step 6's exact
"no orphaned reservation caused by transaction failure" requirement).

## Concurrency (Module 09 Step 7 — this milestone's exact "Stock=1, two buyers" scenario)

No new concurrency mechanism was built for B5 — it inherits B4's atomic conditional
`UPDATE` guarantee entirely by routing through `InventoryService::reserve()`. Two
concurrent order-creation requests for the same last unit cannot both succeed: the
second request's reservation attempt affects zero rows at the database level and
throws `InsufficientStockException`, which `OrderController::store()` catches and
returns as a 422 — the whole order transaction (including the `Order` row) rolls back,
so no half-created order is left behind. Tested (sequential simulation, honestly
labeled) in `OrderConcurrencyTest`.

## Idempotency (Module 09 §23-24)

`orders.idempotency_key` — unique `(store_id, idempotency_key)` constraint, checked
FIRST in `createOrder()`, before any entitlement check or inventory side effect. A
retried request with the same key returns the already-created order (200, not 201 —
`OrderController::store()` uses Eloquent's `wasRecentlyCreated` flag to distinguish
genuine creation from idempotent replay, using `load()` rather than `fresh()` inside
the service specifically to preserve that flag correctly). Each line item ALSO gets
its own reservation idempotency key (`{order_key}:item:{index}`), so a retried order
creation cannot double-reserve stock even if the outer order-level check somehow raced
(defense in depth, not required for correctness given the outer check, but free given
B4's existing per-reservation idempotency mechanism).

## Order State Machine (Module 09 §15, Step 5)

`OrderStateMachine` is the ONLY place a status transition's validity is decided — no
controller writes `order.status` directly (verified by inspection: `Order`'s
`$fillable` includes `status` only because the initial `create()` call sets it once;
every subsequent change goes through `OrderService::transitionTo()`, which always
calls `assertCanTransition()` first). Only the transitions B5's actual scope exercises
are wired (creation→confirmation, several→cancelled); the remaining ~10 of Module 09
§15's 17 states are schema-ready enum cases with no registered transitions yet —
future modules (Fulfillment, Shipping, Payments, Returns) extend `TRANSITIONS`, never
bypass this class.

## Cancellation (Module 09 §41-44)

`OrderStateMachine::CANCELLABLE_STATUSES` uses the module's own worked example
verbatim (Draft/PendingConfirmation/Confirmed/Processing/ReadyToFulfill are
cancellable; Shipped/Delivered are not — those require the future return workflow).
Cancellation releases every `Active` reservation tagged `reference_type='order'` for
this order (reusing `InventoryService::release()`), records reason/note/actor/
timestamp on the `Order` row itself AND as a timeline event, and is fully idempotent
by state (an already-cancelled order's second cancel attempt is rejected by the state
machine, not by a separate idempotency key).

## Events (Module 09 Final Rule #21 — real outbox, not deferred)

`order.created` and `order.cancelled` are wired to the real outbox
(`RecordsOutboxEvents`, ADR-004) inside the same transaction as the state change they
describe — same reasoning as B4's low-stock event: Module 09's own final rules
explicitly mandate reliable delivery, so this is not speculative infrastructure.

## Tenant Isolation Beyond the Model Layer

`resolveAndPriceItem()` uses tenant-scoped `find()` for both `Product` and
`ProductVariant` (via `BelongsToTenant`'s global scope) — a cross-tenant product/
variant ID reference produces a 422 validation error, mirroring Phase B3/B4's
identical pattern, never a silently-created cross-tenant order line. `Order`/
`OrderItem`/`Customer`/`OrderTimelineEvent` all use `BelongsToTenant`.

## API Endpoints Added in B5

| Method | Path | Notes |
|---|---|---|
| GET | `/api/v1/orders` | staff-facing list, tenant-scoped |
| GET | `/api/v1/orders/{order}` | detail with items |
| POST | `/api/v1/orders` | idempotent creation |
| POST | `/api/v1/orders/{order}/cancel` | state-machine-guarded |
| GET | `/api/v1/orders/{order}/timeline` | append-only history |

## Frontend

`Pages/Orders/Index.tsx` — list with status and a cancel action gated by client-side
status display only (the server independently re-validates via the state machine
regardless of what the button shows — Module 09 Step 14: "the server remains
authoritative"). No order detail page, no admin order creation form built in B5
(kept to the minimal-admin-shell discipline).

## Deferred (see inspection findings for the full, explicit list)

Payment processing, shipping/fulfillment execution, returns/refunds/exchanges, order
editing after confirmation, notes/tags/priority, search/filtering, export/import,
external order IDs/marketplace/POS, webhooks, fraud/COD-risk foundations, analytics/
reporting, data retention/deletion/anonymization, storefront customer-facing
authentication and self-service order access.
