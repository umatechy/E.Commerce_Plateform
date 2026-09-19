# Phase B4 — Inventory & Stock Management Architecture (Module 08)

See `docs/development/b4-inspection-findings.md` for the explicit scope decision and
the **critical cross-cutting `store()` relationship fix** found during this milestone
(affects every tenant-owned model since Phase B1 — read that section first).

## Entities

- **Warehouse** — tenant-owned. Every store gets one default ("Main Warehouse")
  automatically at creation (`StoreObserver`, Module 08 §14). Creating a second
  warehouse requires the `inventory.multi_warehouse` feature entitlement
  (Business/Premium, Module 08 §71).
- **Inventory** — the balance record (Module 08 §8), keyed by
  (store, warehouse, product OR variant). Deliberately "dumb": it holds current
  columns only; every mutation goes through `InventoryService`.
- **StockMovement** — append-only ledger (Module 08 §29-31). Every enum case from
  the module's own recommended list exists; B4 itself only ever writes
  `OpeningBalance`, `AdjustmentIn`, `AdjustmentOut`, `Reservation`, and
  `ReservationRelease` — the rest are reserved for Orders/Purchasing/Transfers/Returns.
- **StockReservation** — lifecycle per Module 08 §21 (`Pending → Active → Released/
  Converted/Expired/Cancelled`).

## Stock Balance Calculation

Module 08 §6's full formula is `AVAILABLE = ON_HAND - RESERVED - OTHER_COMMITTED_STOCK`.
B4's documented simplification (no Orders/commitment concept exists yet):

```
AVAILABLE = max(0, ON_HAND - RESERVED)
```

`Inventory::available()` is the single, centralized place this is computed (Module 08
§6: "the exact calculation must be centralized in the inventory domain service") —
no controller, Resource, or test recomputes this independently.

## Concurrency Strategy (Module 08 §23 — documented per its explicit requirement)

**Chosen strategy: single atomic conditional `UPDATE` statement, decision made by
checking affected-row count — not a prior `SELECT`.**

```sql
UPDATE inventories SET on_hand = on_hand + (:delta)
WHERE id = :id AND on_hand >= :abs_delta   -- only when delta is negative
```

```sql
UPDATE inventories SET reserved = reserved + :qty
WHERE id = :id AND (on_hand - reserved) >= :qty   -- only when overselling disabled
```

There is no "read stock, decide in PHP, write stock" round-trip for the
safety-critical decision — the database evaluates the WHERE condition and applies the
change atomically. Two concurrent requests racing for the same last unit can never
both succeed: only one UPDATE can match a WHERE clause that requires the *current*
row value to satisfy it, so the second request's statement affects zero rows and the
service throws `InsufficientStockException`.

**Why this over `SELECT ... FOR UPDATE` row locking**: no explicit lock/unlock
lifecycle, no deadlock risk against another inventory row, no lock-contention queue
under high concurrency. This is the same philosophy as Phase B2's
`UsageTrackingService` (atomic `INSERT ... ON DUPLICATE KEY UPDATE`) — a consistent
pattern across the codebase rather than a new strategy invented per module.

**Transaction boundary**: the balance `UPDATE` and its `StockMovement` ledger row are
always written inside the same `DB::transaction()` — verified in every
`InventoryService` method by inspection.

## Overselling (Module 08 §24)

A per-store boolean, `stores.allow_overselling` (default `false` — the safe choice).
When `false`, every negative-stock and over-available-reservation attempt is blocked
by the atomic guard above. When `true`, the guard is skipped for **reservations**
(the pathway a future Orders module will use to sell) but **never for adjustments**
— adjustments represent physical stock counts/corrections, a different concern from
sales policy, and always require the result to stay ≥ 0 regardless of the store
setting. This is why no database `CHECK (reserved <= on_hand)` constraint was added:
it would conflict with the intentional overselling-reservation behavior, and a CHECK
constraint cannot conditionally reference another table's per-store setting — this
invariant is necessarily enforced at the application layer
(`InventoryService::reserve()`), a documented, reviewed trade-off.

## Idempotency (Module 08 §59)

Every mutating `InventoryService` method accepts an idempotency key, checked against
a unique `(store_id, idempotency_key)` database constraint on both `stock_movements`
and `stock_reservations`. A retried call with the same key returns the
already-created row rather than reapplying the change — verified explicitly in
`test_duplicate_adjustment_request_is_idempotent` and
`test_duplicate_reservation_request_returns_the_same_reservation`.

## Reservation Expiry (Module 08 §22)

`inventory:expire-reservations` console command (scheduled every minute, same
dispatcher-pattern precedent as ADR-004's `outbox:publish`) finds `Active` reservations
past `expires_at` and releases them (`ReservationStatus::Expired`), freeing their
`reserved` quantity.

## Low Stock (Module 08 §27-28) — Real Outbox Event, Not Deferred

Unlike B2/B3's deferred events (no consumer existed yet), Module 08's own Final
Architectural Rule #21 ("inventory events should use reliable delivery mechanisms")
is explicit enough that B4 wires a real event: `adjustStock()` checks
`Inventory::isLowStock()` after every adjustment and, on the transition into low
stock, writes an `inventory.low_stock_detected` outbox event (ADR-004,
`RecordsOutboxEvents`) — at most once per hour per inventory record (idempotency key
includes the current hour) to avoid alert spam on repeated small adjustments.

## Cost Data (Module 08 §43-45, §74) — Explicitly Not Implemented

Per this milestone's own instruction ("if valuation method is not defined, document
the missing decision and do not silently choose a financial accounting policy"): **no
cost/valuation field exists on `Inventory` in B4.** Module 08 itself does not specify
FIFO/Average/Standard costing, and inventing one would be exactly the kind of
undocumented commercial/accounting decision every milestone's prompt has consistently
forbidden. This is an open decision for a future costing-focused pass, not an
oversight — `InventoryResource` correspondingly has no cost field to ever leak.

## Tenant Isolation Beyond the Model Layer

`InventoryController::assertCatalogRelationsBelongToTenant()` and the inline
warehouse check in `store()` re-validate `product_id`, `product_variant_id`, and
`warehouse_id` via tenant-scoped `find()` calls — mirroring
`ProductController::assertRelationsBelongToTenant()`'s identical pattern from Phase
B3. A cross-tenant reference to any of these three gets a 422 validation error, never
a silently-created cross-tenant association.

## Package Entitlement Integration

`inventory.multi_warehouse` (feature flag, Module 04-style, seeded
false/true/true for Basic/Business/Premium) gates the SECOND and later warehouse a
store tries to create. No numeric warehouse-count limit was invented beyond "more
than one" — Module 08 §71 lists "Multiple warehouses" as a boolean Business+
capability, not a specific number, so a feature flag (not a usage limit) is the
correct entitlement type here.

## API Endpoints Added in B4

| Method | Path | Notes |
|---|---|---|
| GET/POST/PUT | `/api/v1/warehouses[/{warehouse}]` | multi-warehouse entitlement-gated |
| GET/POST | `/api/v1/inventory[/{inventory}]` | list/detail/create record |
| GET | `/api/v1/inventory/{inventory}` | detail |
| POST | `/api/v1/inventory/{inventory}/adjust` | Module 08 §33 |
| POST | `/api/v1/inventory/{inventory}/opening-stock` | Module 08 §32 |
| GET | `/api/v1/inventory/{inventory}/movements` | ledger history |
| POST | `/api/v1/inventory/{inventory}/reservations` | Module 08 §20 |
| POST | `/api/v1/reservations/{reservation}/release` | Module 08 §21 |

## Frontend

`Pages/Inventory/Index.tsx` — list with on-hand/reserved/available/low-stock status
and a minimal adjustment action. No warehouse management UI, no reservation UI, no
movement-history detail view built in B4 (kept to the "do not implement the entire
Orders or Purchasing UI" / minimal-admin-shell discipline established since Phase B1).

## Deferred (see inspection findings for the full, explicit list)

Multi-warehouse allocation logic, Stock Transfer, Stock Count/variance, Purchase
Receiving, Returns/Damaged stock, inventory costing/valuation, Backorder/Preorder,
batch/lot/serial/expiry, offline/POS sync, import/export, dashboards/reports, dead
stock/aging, search/storefront integration, adjustment approval workflow, "committed"
stock (tied to confirmed Orders, Phase B5).
