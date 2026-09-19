# Phase B4 — Step 1: Inspection + Scope Decision (Inventory: Module 08)

## Inspection of Existing Code

- `Product`/`ProductVariant` (B3) — reusable as-is. Module 08 §4 "Product Inventory
  Ownership" requires inventory to attach to Product (simple) OR ProductVariant
  (variable) — neither model needs any change; a new `Inventory` row references
  whichever is appropriate.
- `EntitlementService::assertCanUse()` (B2) — reused for multi-warehouse gating
  (Module 08 §71: "Business: Multiple warehouses" is package-gated).
- `RecordsOutboxEvents` (B0/B2) — reused for `stock_adjusted`/`low_stock_detected`
  events (Module 08 Final Rule #21: "Inventory events should use reliable delivery
  mechanisms" — unlike B2/B3's deferred events, this module's own final rules
  explicitly mandate reliable delivery, so B4 wires real outbox events rather than
  deferring them).
- `BaseTenantPolicy` (B1/B3) — reused verbatim for `InventoryPolicy`/`WarehousePolicy`.
- No genuine B0-B3 defects were found that affect B4 scope (unlike B2's and B3's
  inspections, which each found real bugs in prior phases — this phase's dependencies
  were already exercised correctly).

## Scope Decision (Module 08 spans 102 sections — same discipline as B3's inspection)

**B4 implements**: Warehouse (single-default + entitlement-gated multi-warehouse),
Inventory record (on-hand/reserved/available, reorder point), append-only Stock
Ledger (movement types per §31, all enum values present but only a documented subset
actually triggered this milestone), Stock Adjustment (with reason, actor, audit),
Opening Stock, Reservation lifecycle with expiry, concurrency-safe atomic stock
mutation, overselling protection (store-level toggle), low-stock detection, tenant
isolation, entitlement integration, idempotency, a focused API, minimal admin UI.

**Explicitly deferred** (named so nothing is silently dropped, consistent with B3's
inspection-findings convention):
- Multi-warehouse *allocation logic* (§53-55: which warehouse fulfills an order) —
  no Orders module exists yet to allocate for.
- Stock Transfer (§37-40) and Stock Count/variance (§35-36) — dedicated workflows
  Module 08 itself describes as their own blueprints (§100's "Required Follow-Up
  Artifacts" lists them separately from the core inventory blueprint).
- Purchase Receiving foundation (§41-42) — belongs with a future Purchasing module;
  Opening Stock (§32) is implemented as the one stock-in mechanism B4 needs now.
- Returns/Damaged stock (§46-47) — Module 08 itself says "detailed order return
  workflow belongs to Module 09."
- Inventory costing/valuation (§43-45) — Module 08 explicitly says "the initial
  release may use a simpler approach" and does NOT define a specific accounting
  method (FIFO/Average/Standard) — per this milestone's own instruction ("If
  valuation method is not defined: document the missing decision and do not silently
  choose a financial accounting policy"), **no cost/valuation field is added to the
  Inventory record at all in B4**. This is a genuine open decision, not an oversight.
- Backorder/Preorder (§25-26), batch/lot/serial/expiry (§68-70) — explicitly
  "foundation for future," no concrete fields needed until a module requires them.
- Offline/POS sync (§57-58), inventory import/export (§62-63), dashboard/reports
  (§64-65), dead stock/aging (§66-67), search integration (§81), storefront
  integration (§82) — no consumer exists yet for any of these.
- Adjustment approval workflow (§34) — "Premium or configured stores may require
  approval" is an optional future control, not required for B4's core engine.
- "Committed" stock (§48) — distinct from Reserved, tied to *confirmed orders*, which
  don't exist yet (Phase B5). `AVAILABLE = ON_HAND - RESERVED` is B4's documented
  formula (the module's own §6 formula minus the not-yet-applicable
  `OTHER_COMMITTED_STOCK` term) — extensible when Orders introduces commitment.

## CRITICAL Cross-Cutting Fix — `store()` Relationship Missing on Every Tenant-Owned Model

**This is the most significant finding across B1–B4.** `BelongsToTenant` (the trait
applied to every tenant-owned model since Phase B1) never defined a `store()`
relationship. Laravel's `Model::factory()->for($store)` factory helper — used
throughout **every single Feature test file since Phase B1** (`Role::factory()->for($store)`,
`Category::factory()->for($store)`, `Product::factory()->for($store)`,
`Subscription::factory()->for($store)`, and now B4's `Warehouse`/`Inventory`
factories) — requires this exact relationship to exist so it can determine the
foreign key to set.

**Without it, every one of those test calls would have thrown
`BadMethodCallException: Call to undefined method ...::store()` the first time any
test actually ran against a real PHP runtime.** This was invisible in every prior
checkpoint because nothing in this Claude App environment has ever executed PHP —
each checkpoint's "Runtime Verification" section correctly said so, but this is the
first time that honest limitation is confirmed to have hidden a real, suite-wide
defect rather than just an unconfirmed-but-probably-fine implementation.

**Fixed** by adding one `store(): BelongsTo` method to the shared `BelongsToTenant`
trait itself (`app/Domain/Tenancy/Support/BelongsToTenant.php`) — this retroactively
fixes every tenant-owned model (`Role`, `Category`, `Brand`, `Product`,
`ProductVariant`, `Subscription`, `UsageCounter`, `OutboxEvent`, `Warehouse`,
`Inventory`, `StockMovement`, `StockReservation`) in one change, rather than adding
an identical method to a dozen individual classes. `AttributeValue` correctly does
NOT get this (it never used `BelongsToTenant` to begin with — isolation is inherited
from its parent `Attribute`).

**This is flagged as the top priority item for the VS Code phase**: run the full test
suite immediately upon opening the repository, specifically to confirm this fix
actually resolves the issue for real, since it has never been executed against a
real PHP runtime.


- **`ProductController::store()` (Phase B3) caught `FeatureNotEntitledException` and
  `UsageLimitExceededException` but not `SubscriptionInactiveException`** —
  `assertFeatureEntitled()`/`assertCanUse()` can throw any of these three, and an
  inactive-subscription store attempting to create a product would have hit an
  uncaught exception (a 500 error) instead of a clean 403. Found while writing B4's
  `WarehouseController` (which needed the identical catch pattern) and fixed in both
  places — `ProductController` retroactively, `WarehouseController` correctly from
  the start.

