============================================================
PHASE B4 CHECKPOINT
============================================================

Phase:
Development Phase B

Milestone:
B4 — Inventory & Stock Management (Module 08)

Status:
Implemented in Claude App environment as far as this environment allows. Runtime
execution deferred to VS Code phase (no PHP/Composer/MySQL/Redis/network available
here — unchanged since Milestone-0 preflight).

Inspection Findings:
Step 1 inspection + explicit scope decision performed before any code
(docs/development/b4-inspection-findings.md — Module 08 spans 102 sections; B4
implements the core engine and explicitly lists every deferred area). CRITICAL
finding: `BelongsToTenant` (applied to every tenant-owned model since Phase B1) was
missing a `store()` relationship, which `Model::factory()->for($store)` — used
throughout the ENTIRE test suite since Phase B1 — requires to exist. This would have
caused a BadMethodCallException on the first real test execution across nearly every
Feature test in the project. Also found: `ProductController::store()` (Phase B3) did
not catch `SubscriptionInactiveException`, risking an uncaught 500 error.

Fixes Applied:
1. Added `store(): BelongsTo` to the shared `BelongsToTenant` trait — fixes every
   tenant-owned model (Role, Category, Brand, Product, ProductVariant, Subscription,
   UsageCounter, OutboxEvent, Warehouse, Inventory, StockMovement, StockReservation)
   in one change.
2. Added `SubscriptionInactiveException` to `ProductController::store()`'s catch
   block (Phase B3 retroactive fix) and to `WarehouseController::store()` (correct
   from the start).
3. Removed a planned `CHECK (reserved <= on_hand)` database constraint before it was
   finalized, after design review showed it would conflict with intentional
   overselling-reservation behavior — moved the invariant to the application layer
   with documented rationale (docs/architecture/b4-inventory.md).

Inventory:
Warehouse (tenant-owned, one default per store auto-created by StoreObserver,
entitlement-gated multi-warehouse) + Inventory record (store + warehouse +
product/variant, on_hand/reserved/incoming/reorder_point). Inventory model is
intentionally "dumb" — all mutation goes through InventoryService.

Stock Balance:
AVAILABLE = max(0, ON_HAND - RESERVED) — B4's documented simplification of Module 08
§6's full formula (no Orders/"committed stock" concept exists yet). Centralized in
Inventory::available(), never recomputed elsewhere.

Stock Ledger:
StockMovement — append-only (no update()/delete() call exists anywhere against this
model, verified by inspection). All 14 movement types from Module 08 §31 exist as
enum cases; B4 writes OpeningBalance, AdjustmentIn, AdjustmentOut, Reservation, and
ReservationRelease only — the rest are reserved for future modules.

Stock Movements:
Every movement records previous_on_hand, new_on_hand, reason, actor_id,
idempotency_key, reference_type/id — Module 08 §30's full field list.

Reservations:
Full lifecycle (Pending/Active/Released/Converted/Expired/Cancelled per Module 08
§21). Atomic reserve via conditional UPDATE; release is a safe no-op on an
already-terminal reservation (checked by status, not just idempotency key). Expiry
handled by a scheduled console command (inventory:expire-reservations, same
dispatcher precedent as ADR-004's outbox:publish).

Stock Adjustments:
Requires reason + quantity + actor (Module 08 §33), always transactional, always
creates a ledger entry, never allowed to take on_hand negative — including when the
store allows overselling (documented decision: overselling is a sales policy, not an
adjustment/data-correction policy).

Opening Stock:
Allowed exactly once per inventory record (on_hand must be 0, no prior movement
exists) — a second attempt throws DuplicateOpeningStockException, directing the
caller to use adjustStock() instead. Never silently overwrites existing inventory.

Low Stock:
Inventory::isLowStock() (available <= reorder_point). Triggers a real outbox event
(inventory.low_stock_detected) on the transition into low stock — at most once per
hour per record — per Module 08's own Final Architectural Rule #21 on reliable event
delivery (unlike B2/B3's deferred events, this one has an explicit spec mandate).

Negative Stock:
Prevented by the atomic conditional UPDATE's WHERE clause for adjustments always;
for reservations, prevented unless the store's allow_overselling=true, in which case
reservations may exceed on_hand intentionally (the pathway a future Orders module
will use to sell) — available() still floors at 0 for display purposes.

Concurrency Strategy:
Single atomic conditional SQL UPDATE per balance mutation, decided by checking
affected-row count (not a prior SELECT) — documented in full in
docs/architecture/b4-inventory.md "Concurrency Strategy". Same philosophy as Phase
B2's UsageTrackingService (atomic INSERT ... ON DUPLICATE KEY UPDATE), not a new
pattern invented for this module. Chosen over SELECT ... FOR UPDATE row locking to
avoid deadlock risk and lock-contention queuing.

Idempotency:
Unique (store_id, idempotency_key) constraints on both stock_movements and
stock_reservations. Every mutating InventoryService method checks for an existing
row by key before applying any change — a retried request returns the
already-created result rather than double-applying.

Tenant Isolation:
Warehouse/Inventory/StockMovement/StockReservation all use BelongsToTenant.
Cross-tenant product_id/product_variant_id/warehouse_id references rejected via
explicit tenant-scoped re-validation (mirrors Phase B3's identical pattern). 7
dedicated tenant-isolation test methods.

Authorization:
InventoryPolicy, WarehousePolicy — both extend BaseTenantPolicy, both registered
explicitly in AppServiceProvider. Every mutating endpoint calls Gate::authorize()
before touching InventoryService.

Entitlements:
inventory.multi_warehouse feature flag (Basic=false, Business/Premium=true) gates
creating a second warehouse. No numeric warehouse-count limit invented — Module 08
§71 describes this as a boolean Business+ capability, not a specific number.

API:
GET/POST/PUT /api/v1/warehouses[/{warehouse}], GET/POST /api/v1/inventory[/{inventory}],
POST .../adjust, POST .../opening-stock, GET .../movements, POST
.../reservations, POST /api/v1/reservations/{reservation}/release. Every endpoint
validated, authorized, tenant-scoped, idempotency-aware where mutating.

Frontend:
Pages/Inventory/Index.tsx — list with on-hand/reserved/available/low-stock status
and a minimal adjustment action. No warehouse management, reservation, or
movement-history UI built (kept to the established minimal-admin-shell discipline).

Events:
inventory.low_stock_detected wired to the real outbox (ADR-004) — the first
non-deferred domain event in the project (B2/B3 deferred theirs; Module 08's own
Final Rule #21 explicitly mandates reliable delivery here).

Outbox:
Reused RecordsOutboxEvents (Phase B0/B2) — no parallel event delivery system created.

Cache:
Not implemented this milestone — no public storefront read path exists yet to cache;
documented as deferred, not silently omitted.

Audit:
Satisfied by the append-only StockMovement ledger design itself (actor, reason,
before/after quantities, timestamp) rather than a separate audit log table — Module
08 §61's requirement is met structurally.

Database:
5 new/modified migrations: stores.allow_overselling (additive column), warehouses
(new table), inventories (new table), stock_movements (new table), stock_reservations
(new table). No existing table's existing column altered or removed. No destructive
operation performed.

Tests Created:
30 new test methods across 4 Feature test files:
- tests/Feature/Inventory/InventoryTest.php — 5 methods
- tests/Feature/Inventory/StockAdjustmentTest.php — 9 methods
- tests/Feature/Inventory/ReservationTest.php — 9 methods
- tests/Feature/Inventory/InventoryTenantIsolationTest.php — 7 methods
Plus 2 new model factories (Warehouse, Inventory). Combined with all carried-forward
B0/B1/B2/B3 tests: 124 test methods total across the whole suite (verified by direct
grep count, not estimated).

Tests Executed:
NONE.

Runtime Verification:
NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION. No PHP, Composer, MySQL, or
Redis runtime is available in this Claude App sandbox; no outbound network access to
Packagist either. Every test, all 5 new/modified migrations, PHPStan, ESLint, npm
build, and the CI workflow itself have been authored and statically reasoned about,
never executed. A lightweight Node.js-based brace-balance check was run across all
new/modified PHP files as an additional (non-substitute) sanity pass — no mismatches
found. The concurrency test is explicitly simulated sequentially, not under real
parallel load — flagged as the highest-priority item to verify for real once a
runtime is available, alongside the critical store() relationship fix.

Security Review:
Performed (docs/security/b4-security-review.md) — 15-item checklist plus a dedicated
concurrency review, reviewed end-to-end. 1 critical cross-cutting issue found and
fixed (store() relationship), 2 additional issues found and fixed (subscription-
inactive exception handling, CHECK constraint design conflict). 1 documented,
non-critical limitation carried forward (MySQL NULL-uniqueness gap for simple-product
inventory records, mitigated at the application layer).

Documentation:
docs/development/b4-inspection-findings.md, docs/architecture/b4-inventory.md,
docs/security/b4-security-review.md, this checkpoint. Project Bible, SRS, and ADR
status were not modified.

Known Limitations:
- Nothing in this milestone has been executed against a real runtime — the critical
  store() relationship fix in particular has never been confirmed against a real
  PHP/MySQL execution.
- Concurrency test coverage is sequential simulation only, not genuine parallel load.
- No inventory costing/valuation field exists (Module 08 explicitly does not define
  a method — documented open decision, not an oversight).
- MySQL NULL-uniqueness gap for simple-product inventory duplicate prevention,
  mitigated at the application layer only (see security review "Database
  Constraints").
- No warehouse management, reservation, or movement-history frontend UI beyond the
  basic inventory list + adjust action.

Deferred VS Code Verification:
1. composer install / npm install.
2. php artisan migrate (5 new/modified migrations, on top of B0-B3's).
3. php artisan test — all 124 test methods, for real PASS/FAIL results. HIGHEST
   PRIORITY: confirm the store() relationship fix actually resolves the
   BadMethodCallException risk across the whole suite.
4. composer stan, npm run lint, npm run build.
5. A genuine concurrent-request test against a real MySQL instance for the
   "stock=1, two buyers" scenario, beyond this milestone's sequential simulation.

Next Milestone:
Phase B5 — Orders (Module 09), per the approved milestone map. B4 exit criteria are
met: inventory foundation, stock balance, stock ledger, reservations, adjustments,
opening stock, low-stock detection, negative-stock policy, concurrency strategy, and
idempotency all exist and are documented; tenant isolation and authorization are
enforced; entitlement integration exists; required API, frontend, and tests exist;
security review and documentation are complete. Phase B5's own Step 1 will need to
inspect InventoryService's reserve()/release() methods (already exposing
reference_type/reference_id fields for exactly this purpose) before writing any
Order code, since Orders is the first real consumer of the reservation foundation
built here.
