============================================================
PHASE B6 CHECKPOINT
============================================================

Phase:
Development Phase B

Module:
B6 — Cart, Wishlist & Checkout (Module 11)

Implementation Summary:
Implemented the first customer-facing commerce flow: a customer authentication
boundary (Customer is now its own Sanctum-authenticatable identity, structurally
separate from staff Users), guest + registered-customer Cart with server-side
provisional pricing and live revalidation, an authenticated-customer-only Wishlist,
and a one-shot Checkout that performs final validation and calls Phase B5's
OrderService::createOrder() directly and unchanged. No duplicate order-creation or
inventory-reservation logic was introduced anywhere in B6. Runtime execution remains
deferred to VS Code — nothing in this milestone has been executed against a real
PHP/MySQL runtime.

Bugs/Design Issues Found and Fixed (caught during design/implementation, not
discovered as post-hoc bugs in already-committed code):
1. ResolveTenantContext had no case for a Customer principal at all — would have
   been a fatal error the moment any customer route ran (Customer has no
   activeStoreId() method). Fixed with an explicit instanceof Customer branch
   resolving directly from Customer.store_id.
2. No tenant-resolution mechanism existed for anonymous/guest requests — guest cart
   (Module 11 §6) cannot function without knowing which store a guest is shopping
   at. Fixed with a documented interim X-Store-Slug header mechanism (explicitly
   NOT a security credential — see architecture doc), pending Module 19.
3. auth:customer middleware, if applied naively to guest-accessible Cart/Checkout
   routes, would have rejected every guest with a 401 (Laravel's standard auth:
   middleware always aborts on failed resolution). Fixed with a new
   AttemptCustomerAuthentication ("optional auth") middleware.
4. A client-supplied guest cart token could have become a new cart's real,
   security-relevant identifier if it didn't match an existing cart — undermining
   Module 11 §63's "non-sequential" (server-generated) requirement. Fixed before
   being left in the codebase: new carts always receive a freshly generated,
   cryptographically random token; a presented token is only ever used for lookup.

Architectural Decisions:
- Customer authentication uses its own Sanctum guard (`customer`), sharing only the
  underlying token table with staff's `sanctum` guard — Module 10 §3's "logically
  separated... even if they share infrastructure" requirement, made concrete via two
  new explicit type-check middlewares (EnsureCustomerPrincipal, EnsureStaffPrincipal)
  since Sanctum's guard alone cannot enforce this distinction.
- No persisted multi-step Checkout Session state machine was built — Checkout is
  synchronous/one-shot (Cart.status: Active → Converted directly), matching B5's own
  "auto-confirm, no payment gateway" precedent, since the session state machine
  exists specifically to coordinate Payment/Shipping gateway steps that don't exist
  yet.
- Wishlist is authenticated-customer-only; guest wishlist is explicitly client-side/
  local-storage per Module 11 §69, not built server-side.
- Cart ownership authorization uses direct identity-match checks in controllers
  (customer_id === authenticated customer's id, or guest_token possession), not the
  Role/Permission-based Policy pattern used for staff resources — documented as a
  deliberate difference in authorization model, not an inconsistency.

Files/Modules Changed:
- New domain: app/Domain/Cart/ (Models: Cart, CartItem, WishlistItem, CartStatus;
  Services: CartService, CheckoutService; Http/{Controllers,Requests,Resources};
  Exceptions).
- New in app/Domain/Orders/: CustomerAuthController, RegisterCustomerRequest,
  LoginCustomerRequest, CustomerResource.
- Modified: Customer model (now Authenticatable + HasApiTokens), config/auth.php
  (created — customer guard/provider added), ResolveTenantContext (Customer +
  guest-slug branches), bootstrap/app.php (new middleware aliases + route file),
  routes/api_v1.php (staff.principal added), PackageSeeder (wishlist.basic feature).
- New: app/Http/Middleware/{EnsureCustomerPrincipal,EnsureStaffPrincipal,
  AttemptCustomerAuthentication}.php, routes/api_v1_customer.php.
- New factory: CartFactory.

Migrations:
5 new/modified migrations: add authentication columns to customers (additive),
carts, cart_items, wishlist_items (all new-table). No existing table's existing
column altered, renamed, or removed. No destructive operation performed.

API Changes:
POST /api/v1/customer/{register,login,logout}, GET /api/v1/customer/me, GET/POST
/api/v1/wishlist + DELETE/{item}/move-to-cart, GET/POST/PUT/DELETE /api/v1/cart[/items],
POST /api/v1/checkout. All under the existing ADR-005 /api/v1/... versioning.

Authentication Changes:
Customer is now Sanctum-authenticatable via a dedicated `customer` guard, separate
from staff's `sanctum` guard. Two new middlewares (EnsureCustomerPrincipal,
EnsureStaffPrincipal) explicitly enforce that a token issued for one principal type
is never accepted on the other's routes — applied to every new B6 customer route
AND retroactively to the entire existing B1-B5 staff route group.

Cart Architecture:
Cart + CartItem, tenant-owned, owned by either a Customer or a guest_token (never
both). CartService is the sole writer. Prices are provisional (price_at_add_minor
for change-detection only); CartService::totals() recomputes live prices/
availability on every read, used by both the cart display endpoint and Checkout's
final validation. No inventory reservation happens at the cart level.

Wishlist Architecture:
WishlistItem, authenticated-customer-only, duplicate-safe via firstOrCreate against
a real unique constraint, always reports live product availability. Gated by the
wishlist.basic feature flag (all three package tiers).

Checkout Architecture:
One-shot, synchronous. CheckoutService performs final validation (reusing
CartService::totals(), not duplicating it) then calls OrderService::createOrder()
(Phase B5, completely unchanged) inside an outer transaction that also marks the
cart Converted — a failure anywhere rolls back both together.

OrderService Integration:
OrderService::createOrder()'s method signature and internal logic were NOT modified.
CheckoutService is purely a caller, translating Cart items into the exact input
shape OrderService already expected from Phase B5's staff-facing OrderController.

Inventory Integration:
No new inventory logic. CartService never calls InventoryService. Only
OrderService's existing call to InventoryService::reserve() (via CheckoutService's
call into OrderService) ever reserves stock — inherited entirely from B4/B5.

Idempotency Behavior:
Checkout requires idempotency_key, passed directly into OrderService::createOrder(),
reusing B5's (store_id, idempotency_key) uniqueness mechanism unchanged. No second
idempotency layer was built for B6.

Events/Outbox:
No new outbox events introduced in B6 — checkout produces order.created (and
cancellation, if later used, order.cancelled) exactly as Phase B5 already does,
unchanged.

Security Review:
Performed (docs/security/b6-security-review.md) — this milestone's full 30-item
checklist reviewed end-to-end, plus a B0-B5 regression confirmation. The customer/
staff principal separation (Module 10 §3) was the central architectural risk and is
documented and tested in both directions. 4 design-time issues found and fixed
before being left in the codebase (tenant resolution for Customer/guest principals,
optional auth for guest routes, guest-token generation security). 1 known limitation
documented (Wishlist item IDs use internal auto-increment values, not public_id —
low risk, flagged for follow-up).

Tests Added:
35 new test methods across 6 Feature test files:
- tests/Feature/Cart/CustomerAuthTest.php — 9 methods (includes the 2 critical
  staff/customer principal-boundary tests)
- tests/Feature/Cart/CartTest.php — 9 methods
- tests/Feature/Cart/WishlistTest.php — 6 methods
- tests/Feature/Cart/CheckoutTest.php — 7 methods
- tests/Feature/Cart/CartMergeTest.php — 3 methods
- tests/Feature/Cart/CheckoutConcurrencyTest.php — 1 method
Plus 1 new model factory (Cart). Combined with all carried-forward B0-B5 tests: 182
test methods total across the whole suite (verified by direct grep count, not
estimated).

Tests Actually Executed:
NONE. No PHP, Composer, MySQL, or Redis runtime is available in this Claude App
sandbox; no outbound network access to Packagist either.

Tests Not Executed:
All 182 test methods, including all 35 new to this milestone. The concurrency test
(CheckoutConcurrencyTest) is explicitly a sequential simulation, not genuine
parallel load — flagged as the highest-priority scenario to verify for real once a
runtime is available, consistent with B4/B5's identical precedent.

Static Inspections Performed (EXECUTED vs INSPECTED, per this milestone's explicit
distinction requirement — nothing below was EXECUTED):
- Source inspection of every new/modified file against Module 10/11's requirements.
- A lightweight Node.js-based brace/parenthesis balance check across all new/
  modified PHP files — no mismatches found.
- Route inspection: confirmed every new route resolves to an existing controller
  method with matching parameter names.
- Migration inspection: confirmed foreign keys, tenant_id presence, and index
  coverage for the query patterns CartController/CheckoutController/
  WishlistController actually use.
- Cross-reference check: confirmed OrderService::createOrder()'s exact input shape
  matches what CheckoutService constructs, with zero changes to OrderService itself.
- Middleware-chain inspection: traced the registration order of
  EnsureFrontendRequestsAreStateful → ResolveTenantContext → auth:customer/
  customer.optional → EnsureCustomerPrincipal for the new route groups, consistent
  with the existing staff route group's established ordering.
- Config inspection: confirmed config/auth.php's new `customer` guard/provider
  correctly references the Customer model's namespace.

Known Limitations:
- Nothing in this milestone has been executed against a real runtime.
- Concurrency test coverage is sequential simulation only.
- Wishlist item IDs are internal auto-increment values, not public_id (documented,
  low-risk inconsistency — see security review).
- Cart-level actions are not separately audited (only the resulting Order, if
  checkout succeeds, is auditable via B5's existing OrderTimelineEvent).
- No storefront frontend UI was built in B6 (deferred — see below).

Deferred Functionality:
Persisted multi-step Checkout Session state machine, coupon/discount application
(Module 14), tax calculation, shipping address/method/calculation (Module 13),
payment integration (Module 12), bundle/digital/service-specific checkout handling,
multiple/shared wishlists, abandoned-cart marketing/analytics, fraud/bot-protection
foundations beyond standard rate limiting, guest-to-registered historical order
linking (Module 10 §9's verification-required conversion flow), storefront UI. Full
list with rationale in docs/development/b6-inspection-findings.md.

Git Status:
Verified by direct execution (`git status`) before this checkpoint was written: all
B6 files listed above are new/modified and untracked/unstaged relative to the
previous commit (b12ae2b). No files outside the Cart domain, Customer/auth
integration points, and documentation were touched — confirmed via `git status
--short`.

Git Commit Status:
Commit created: 47d6a1c — "Phase B6: Cart, Wishlist & Checkout (Module 11)".
Verified by direct execution (`git log --oneline` and `git status` both run after
the commit): working tree clean, history now shows three real commits:
5dcb815 (Phase B0-B5), b12ae2b (B5 checkpoint git-status correction), 47d6a1c (this
milestone). No fabricated incremental history — this is the actual, executed commit
hash, not an invented one.

Recommended Next Milestone:
Phase B7 — per the approved milestone map, the next core-commerce dependency is
Module 12 (Payment Management & Gateways) or Module 13 (Shipping & Delivery
Management). Recommend Module 12 next: B6's Checkout already has a clear integration
seam (OrderService's payment_status column, defaulted to Unpaid) waiting for a real
payment coordination step, and B5/B6 both explicitly deferred payment as "the next
module's concern" at every relevant checkpoint. Phase B7's own Step 1 should inspect
OrderService's payment_status handling and CheckoutService's flow before writing any
payment gateway code, since payment confirmation will need to call back into
OrderService's existing (currently unused beyond the default) payment-status
transition point.
