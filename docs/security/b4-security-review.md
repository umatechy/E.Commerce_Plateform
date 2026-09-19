# Phase B4 — Focused Security & Concurrency Review

Static/design-level review only — **NOT EXECUTED — DEFERRED TO VS CODE RUNTIME
VERIFICATION**.

## Critical Finding (see inspection findings for full detail)

**`BelongsToTenant` was missing a `store()` relationship**, which every
`Model::factory()->for($store)` call across the ENTIRE test suite since Phase B1
depends on. This would have caused a `BadMethodCallException` on the first real test
run — the most significant defect found across all four milestones so far. **Fixed**
by adding the relationship once to the shared trait.

## Standard B4 Checklist

| Item | Finding | Status |
|---|---|---|
| **Tenant isolation** | `Warehouse`, `Inventory`, `StockMovement`, `StockReservation` all use `BelongsToTenant`. Cross-tenant read/adjust/reserve/movement-history access → 404 (7 dedicated tests in `InventoryTenantIsolationTest`). | Reviewed — OK |
| **IDOR** | Route-model binding + global scope make a cross-tenant Inventory/Warehouse/Reservation unreachable before any Policy runs. | Reviewed — OK |
| **Unauthorized stock adjustment** | Every mutating endpoint calls `Gate::forUser(...)->authorize('adjust', $inventory)` — verified present in `adjust()`, `openingStock()`, and `ReservationController`'s both methods. | Reviewed — OK |
| **Unauthorized stock reservation** | Same Policy/Gate call as adjustment — no separate, weaker reservation authorization path exists. | Reviewed — OK |
| **Role/permission escalation** | `InventoryPolicy`/`WarehousePolicy` both extend `BaseTenantPolicy` and reuse its `isOwner()` — scoped to the user's own active store membership, cannot be satisfied by Owner status elsewhere. | Reviewed — OK |
| **Cross-tenant product/variant/warehouse references** | **Explicitly tested and enforced** — `assertCatalogRelationsBelongToTenant()` + the inline warehouse check reject any cross-tenant ID with a 422, mirroring Phase B3's identical pattern. 2 dedicated tests. | Reviewed — OK |
| **Negative-stock bypass** | Every stock-decreasing path (`adjustStock` with negative delta) goes through the atomic conditional `UPDATE ... WHERE on_hand >= abs(delta)` — no code path decreases `on_hand` any other way (verified by inspection: `InventoryService` is the only class that writes to `inventories.on_hand`). Explicitly tested including the overselling-enabled case (adjustments never bypass this, by design). | Reviewed — OK |
| **Race conditions** | This milestone's dedicated concern — see `docs/architecture/b4-inventory.md` "Concurrency Strategy". Every balance mutation is a single atomic SQL statement conditioned on the row's own current value, closing the exact "stock=1, two buyers" race described in the prompt. Simulated sequentially in `test_two_reservations_cannot_both_succeed_when_only_one_unit_is_available` (a true parallel-process test is deferred to VS Code with a real MySQL instance — flagged, not claimed as executed). | Reviewed — OK, documented limitation on test depth |
| **Duplicate requests / idempotency** | Unique `(store_id, idempotency_key)` constraints on both `stock_movements` and `stock_reservations`; every mutating service method checks for an existing row by key before applying any change. 2 dedicated tests. | Reviewed — OK |
| **Replay** | Same idempotency mechanism covers replay of a previously-sent request (a retried API call with the same key is indistinguishable from a replay attempt at the service layer — both are safely absorbed). | Reviewed — OK |
| **Mass assignment** | Every model uses explicit `$fillable`; `on_hand`/`reserved` are never client-settable — the only way to change them is through `InventoryService`'s methods, which the Inventory model's own `$fillable` list does not even include (`store_id, warehouse_id, product_id, product_variant_id, on_hand, reserved, incoming, reorder_point, reorder_quantity` — `on_hand`/`reserved` ARE fillable for the initial `create()` at 0/0 default from `StoreInventoryRequest`, which never sends them; every subsequent change goes through raw `DB::table()` UPDATE statements in `InventoryService`, not `$model->update()`). | Reviewed — OK |
| **SQL injection / raw SQL** | `InventoryService` uses `DB::raw("on_hand + ({$delta})")` and `DB::raw("reserved + {$quantity}")` with **integer-typed, already-validated** PHP values (never raw user string input interpolated directly) — `$delta`/`$quantity` are cast `(int)` in the controller before reaching the service. `whereRaw('(on_hand - reserved) >= ?', [$quantity])` uses a parameterized placeholder, not string interpolation. No user-supplied string ever reaches a raw SQL fragment. | Reviewed — OK |
| **Cache leakage** | No inventory data is cached in B4 (documented as deferred — no public storefront read path exists yet to cache). N/A this milestone. | N/A |
| **Event leakage** | `inventory.low_stock_detected` outbox events carry only `inventory_id`, `product_id`, `product_variant_id`, `available`, `reorder_point` — all already tenant-scoped data the event's own `store_id` (set by `RecordsOutboxEvents`, per ADR-004) governs; a consumer re-applies tenant context from the event's own `store_id`, never ambient state (ADR-001 Layer 6, unchanged). | Reviewed — OK |
| **Audit integrity** | Every `StockMovement` is append-only (no `update()`/`delete()` call against this model exists anywhere in the codebase — verified by inspection) and carries `actor_id`, `reason`, `previous_on_hand`, `new_on_hand` — Module 08 §61's audit requirement is satisfied by the ledger design itself, not a separate audit log. | Reviewed — OK |
| **Super Admin boundary** | No new Super Admin surface needed or added for B4 (inventory management is entirely store-scoped) — consistent with B3. | N/A this milestone |
| **Cost/price exposure** | N/A — no cost field exists on `Inventory` in B4 at all (see architecture doc "Cost Data"), so there is structurally nothing to leak. | Reviewed — OK, N/A by design |

## Database Constraints — Known, Documented Limitation

The `inventories` table's composite unique constraint
`(store_id, warehouse_id, product_id, product_variant_id)` does **not** fully protect
against duplicate rows for **simple products** (where `product_variant_id` is always
`NULL`), because MySQL treats `NULL` as distinct from `NULL` in unique-index
evaluation. **Mitigated at the application layer**:
`InventoryController::store()` checks for an existing matching row and returns it
(idempotent `200`, not a `422` or duplicate `201`) rather than relying on the database
constraint alone — tested in `test_creating_duplicate_inventory_record_returns_the_existing_one`.
This remains a defense-in-depth gap for any *other, future* code path that might
create an `Inventory` row directly (bypassing this controller) — flagged explicitly
rather than silently accepted, since ADR-003 prefers database-level enforcement where
possible and this is a documented exception to that preference.

## Issues Found and Fixed Within B4 Scope

1. **`BelongsToTenant` missing `store()` relationship** (see Critical Finding above) —
   the most significant fix in the project so far.
2. **`ProductController::store()` (Phase B3) did not catch `SubscriptionInactiveException`**
   — found while writing B4's identical entitlement-check pattern in
   `WarehouseController`; fixed in both places.
3. **Reserved-vs-on-hand CHECK constraint would have conflicted with intentional
   overselling behavior** — caught during design review before the migration was
   finalized (not a runtime bug, but a design conflict that would have caused every
   overselling-enabled reservation to fail with a database constraint violation);
   resolved by moving the invariant to the application layer with a documented
   rationale.

None of the "found and fixed" items required deleting or resetting existing B0-B3
work. No destructive database operation was performed (all 5 new/modified migrations
are additive or new-table only).
