============================================================
PHASE B5 CHECKPOINT
============================================================

Phase:
Development Phase B

Module:
B5 — Order Management (Module 09)

Implementation Summary:
Implemented the core Order domain: Order + OrderItem (full historical snapshots),
a minimal Customer foundation, order number generation (atomic per-store sequence),
the Order/Payment/Fulfillment state machine (kept as three distinct statuses per
Module 09 §16), server-authoritative pricing (no client price/total ever trusted),
inventory reservation-on-create and release-on-cancel via Phase B4's existing
InventoryService (no duplicate inventory logic), idempotent order creation, an
append-only order timeline, real outbox events for order.created/order.cancelled,
full tenant isolation, entitlement integration (orders.basic feature + the
already-seeded max_monthly_orders limit from Phase B2), a staff-facing API, and a
minimal admin UI. Runtime execution remains deferred to VS Code — nothing in this
milestone has been executed against a real PHP/MySQL runtime (unchanged since
Milestone-0 preflight: no PHP/Composer/MySQL/Redis/network available in this Claude
App sandbox).

Step 1 Inspection Findings (docs/development/b5-inspection-findings.md):
No regressions found in B0-B4 — Product/Variant/Warehouse/Inventory/Reservation/
Idempotency/Outbox/Audit patterns were all reused unchanged. Confirmed
BelongsToTenant::store() (B4's critical fix) intact and used by every new B5 model.
Two design-time issues were caught and fixed BEFORE being committed to the codebase
(not bugs discovered in already-written code, but design flaws caught during
implementation review — see "Important Bugs Found and Fixed" below).

Important Bugs/Design Issues Found and Fixed:
1. OrderNumberGenerator's MySQL atomic-sequence idiom (INSERT ... ON DUPLICATE KEY
   UPDATE next_number = LAST_INSERT_ID(next_number + 1)) would have been unreliable
   for a store's FIRST order — LAST_INSERT_ID(expr) is only evaluated on the ON
   DUPLICATE KEY UPDATE branch, and without a pre-existing sequence row, the first
   call would take the fresh-INSERT branch instead, leaving LAST_INSERT_ID()
   returning an unrelated value (this table has no AUTO_INCREMENT column). Fixed by
   having StoreObserver pre-seed the sequence row (next_number = 0) for every new
   store at creation time, guaranteeing every real generation call takes the
   reliable UPDATE branch.
2. OrderController::store()'s 200-vs-201 status code logic (idempotent replay vs
   genuine creation) relied on Eloquent's wasRecentlyCreated flag, but the original
   OrderService::createOrder() returned $order->fresh(['items']) — fresh() builds a
   brand-new model instance via a new query and does NOT carry over that flag. Fixed
   by using load() (eager-loads onto the SAME instance) instead of fresh().
3. A planned CHECK (reserved <= on_hand) constraint reconsideration from B4 was
   reconfirmed still correctly absent — B5's order reservations exercise exactly the
   overselling-allowed reservation path B4 documented, confirming that design
   decision remains correct under real usage, not just in the abstract.

Architectural Decisions:
- Order creation auto-confirms (PendingConfirmation → Confirmed) immediately, since
  no Payment module (Module 12) exists yet — documented simplification of Module 09
  §19's full flow, matching the COD-flow precedent in §26.
- Stock is RESERVED on order creation, not yet deducted from on_hand — actual
  commitment is deferred to whichever future module introduces a real "commit" step
  (Module 08 §48's Reserved-vs-Committed distinction).
- Order Timeline and Audit Log are ONE table in B5 (order_timeline_events), not two
  separate systems as Module 09 §74 distinguishes — deliberate simplification until
  Module 32's platform-wide audit log exists.
- Mixed-currency orders are rejected outright (all items must share one currency) —
  a documented decision, not an oversight, given no multi-currency architecture
  exists yet (Module 04/06 reserve it for future Premium expansion).
- A missing Inventory record for a requested product/variant is treated as
  out-of-stock (422), not auto-vivified as a phantom zero-stock record.

Files/Modules Changed:
- New domain: app/Domain/Orders/ (Models, Services, Policies, Http/{Controllers,
  Requests,Resources}, Exceptions) — Customer, Order, OrderItem, OrderTimelineEvent,
  OrderStatus, PaymentStatus, FulfillmentStatus, OrderSource, CancellationReason,
  OrderNumberGenerator, OrderStateMachine, OrderService, OrderPolicy,
  OrderController, CreateOrderRequest, CancelOrderRequest, OrderResource,
  OrderItemResource, OrderTimelineEventResource, 3 exception classes.
- Modified: AppServiceProvider (OrderPolicy registration), PermissionSeeder
  (orders.create/orders.cancel added), PackageSeeder (orders.basic feature added to
  all 3 tiers), StoreObserver (order_number_sequences pre-seed; Manager role granted
  order permissions), routes/api_v1.php, routes/web.php.
- New frontend: resources/js/Pages/Orders/Index.tsx.
- New factories: CustomerFactory, OrderFactory.

Migrations:
5 new, additive/new-table-only migrations: customers, order_number_sequences,
orders, order_items, order_timeline_events. No existing table's column altered,
renamed, or removed. No destructive operation performed.

API Changes:
GET /api/v1/orders, GET /api/v1/orders/{order}, POST /api/v1/orders (idempotent),
POST /api/v1/orders/{order}/cancel, GET /api/v1/orders/{order}/timeline. All under
the existing ADR-005 /api/v1/... versioning — no new API version created.

UI Changes:
resources/js/Pages/Orders/Index.tsx — order list with status display and a
server-authoritative cancel action (client shows the button based on status, but the
server independently re-validates via the state machine regardless).

Inventory Integration:
OrderService calls InventoryService::reserve()/release() directly (Phase B4,
unmodified) — no duplicate inventory logic. Every line item's reservation happens
inside the SAME DB::transaction() as the Order/OrderItem rows; a failed reservation
for any line rolls back the entire order, leaving no orphaned reservation and no
partial order.

State Machine:
OrderStateMachine centralizes every status transition; OrderService is the only
caller. Only the transitions B5's actual scope exercises are wired (creation through
confirmation, several statuses through cancellation) — the remaining schema-ready
states from Module 09 §15's 17-state list have no registered transitions yet,
intentionally, for future modules to add.

Idempotency:
orders.idempotency_key, unique per (store_id, idempotency_key), checked first in
createOrder() before any entitlement or inventory side effect. Per-line-item
reservation idempotency keys additionally derived from the order-level key, as
defense in depth.

Events/Outbox:
order.created and order.cancelled wired to the existing RecordsOutboxEvents
mechanism (ADR-004, unmodified), inside the same transaction as the state change —
per Module 09's own Final Architectural Rule #21 on reliable event delivery (same
reasoning as Phase B4's low-stock event).

Security Review:
Performed (docs/security/b5-security-review.md) — this milestone's full 25-item
checklist reviewed end-to-end, plus a B0-B4 regression confirmation. 2 design-time
issues found and fixed before being left in the codebase (order-number sequence
reliability, replay status-code logic). 3 items documented as correctly deferred
(customer-facing isolation — no such surface exists yet; dedicated order-creation
rate limiting; order cache/search index).

Tests Added:
23 new test methods across 4 Feature test files:
- tests/Feature/Orders/OrderCreationTest.php — 10 methods
- tests/Feature/Orders/OrderCancellationTest.php — 7 methods
- tests/Feature/Orders/OrderTenantIsolationTest.php — 5 methods
- tests/Feature/Orders/OrderConcurrencyTest.php — 1 method
Plus 2 new model factories (Customer, Order). Combined with all carried-forward
B0-B4 tests: 147 test methods total across the whole suite (verified by direct grep
count, not estimated).

Tests Actually Executed:
NONE. No PHP, Composer, MySQL, or Redis runtime is available in this Claude App
sandbox; no outbound network access to Packagist either.

Tests Not Executed:
All 147 test methods in the suite, including all 23 new to this milestone. The
concurrency test (OrderConcurrencyTest) is explicitly a sequential simulation, not
genuine parallel load — flagged as the highest-priority scenario to verify for real
once a runtime is available, consistent with B4's identical flag on its own
concurrency test.

Static Inspections Performed:
- Source inspection of every new/modified file for logical consistency against
  Module 09's requirements.
- A lightweight Node.js-based brace/parenthesis balance check across all new PHP
  files in app/Domain/Orders, database/migrations, and routes — no mismatches found.
- Route inspection: confirmed every new route resolves to an existing controller
  method with matching parameter names (e.g. apiResource's implicit {order} binding
  matches OrderController's Order $order parameter).
- Migration inspection: confirmed foreign keys, tenant_id presence on every
  tenant-owned table, and index coverage for the query patterns OrderController and
  OrderService actually use.
- Cross-reference check: confirmed InventoryService's reserve()/release() method
  signatures (from Phase B4) match exactly how OrderService calls them, including
  the reference_type/reference_id parameters B4 built specifically for this purpose.
- Dependency/reference check: confirmed no circular dependency between
  App\Domain\Orders and App\Domain\Inventory (Orders depends on Inventory services;
  Inventory has no knowledge of Orders).
None of this constitutes EXECUTED verification — it is INSPECTED only, per this
milestone's explicit EXECUTED/INSPECTED/NOT EXECUTED distinction requirement.

Known Limitations:
- Nothing in this milestone has been executed against a real runtime.
- Concurrency test coverage is sequential simulation only.
- No customer-facing order access exists (staff-only API) — Module 10/11 will need
  to add that authentication boundary and its own authorization rules.
- No dedicated order-creation rate limiting beyond the platform default.
- Mixed-currency orders are rejected rather than supported (documented decision).
- A missing Inventory record is treated as out-of-stock rather than auto-created.

Deferred Work:
Payment processing (Module 12), shipping/fulfillment execution (Module 13),
returns/refunds/exchanges, order editing after confirmation, notes/tags/priority,
search/filtering, export/import, external order IDs/marketplace/POS, webhooks,
fraud/COD-risk foundations, analytics/reporting/dashboard, data retention/deletion/
anonymization, order cache, order search index, storefront customer-facing
authentication — full list with rationale in
docs/development/b5-inspection-findings.md.

Next Phase:
Phase B6 — per the approved milestone map, the next unimplemented core-commerce
dependency is Module 10 (Customer Management) or Module 11 (Cart/Wishlist/
Checkout) — both are now directly unblocked by B5's minimal Customer foundation and
Order creation API. Recommend Module 11 (Cart/Checkout) next, since it is the actual
customer-facing entry point that will call OrderService::createOrder() from a real
storefront flow (introducing the customer-facing authentication boundary B5
deliberately deferred) — Module 10's fuller Customer Management (addresses, order
history self-service) can follow once that boundary exists. Phase B6's own Step 1
should inspect OrderService's public method signatures (createOrder(), cancelOrder())
before writing any Cart/Checkout code, since Checkout is the first real caller
beyond this milestone's own staff-facing controller.

Git/Commit Status:
Git execution is unavailable in this Claude App sandbox (no git binary invocation
has been performed in this environment for any milestone) — commit execution remains
deferred to the VS Code phase, consistent with every prior checkpoint.
