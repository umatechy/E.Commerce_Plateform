# Phase B6 — Step 1: Inspection + Scope Decision (Cart, Wishlist & Checkout: Module 11)

## Inspection of Existing Code

- `OrderService::createOrder()` (B5) — accepts `items`, `orderData`, `idempotencyKey`
  and already does everything Module 11 §46 "Final Validation" + §51 "Order Creation"
  requires: server-side product/variant resolution, price recalculation, inventory
  reservation (via B4's `InventoryService`), snapshot creation, idempotency. **B6
  calls this method directly and unmodified** — Checkout is a thin orchestration
  layer in front of it, not a second order-creation implementation.
- `InventoryService::reserve()`/`release()` (B4) — unmodified, reused via
  `OrderService`. Cart itself never calls these directly (Module 11 Final Rule:
  "Do NOT permanently reserve inventory merely because an item was added to a normal
  cart" — confirmed as this milestone's own instruction too).
- `Customer` (B5) — existing model, `store_id`/`user_id`(nullable)/`name`/`email`/
  `phone`. **Critical finding**: Module 10 §3 explicitly requires "Customer
  authentication and staff authentication should remain logically separated even if
  they share infrastructure" — B5's `Customer.user_id` column assumed a future
  customer would authenticate AS a platform `User` (the staff/admin identity model,
  which carries `platform_role` and store-staff memberships). Authenticating
  customers that way would risk exactly what Module 10 §3 forbids: "Customers must
  not automatically receive administrative permissions." **This is corrected in B6**
  — see "Critical Architectural Decision" below. `Customer.user_id` is kept
  (nullable, unused by auth) as a schema-ready link for a possible future explicit
  "this customer is also platform staff" linkage, not as the auth mechanism.
- `BaseTenantPolicy`/permission-based Policies (B1 pattern) — **not reused as-is for
  Cart/Wishlist**. Staff Policies check Role/Permission grants; a customer's
  authorization to their own cart/wishlist is an *identity-ownership* question
  (`cart.customer_id === $authenticatedCustomer->id`), not a permission-grant
  question. Documented as a deliberate, reviewed deviation from the Policy pattern,
  not an inconsistency — see architecture doc.
- `BelongsToTenant::store()` (B4 fix) — confirmed present, used by every new B6
  tenant-owned model.
- No regressions found in B0-B5 during inspection.

## CRITICAL Architectural Decision — Customer Authentication Boundary

Module 10 §3/§4 and this milestone's Step 10 both require customer auth to be
"logically separated" from staff auth "even if they share infrastructure," and
explicitly forbid a customer gaining staff permissions.

**Decision**: `Customer` becomes its own Sanctum-authenticatable model (via
`Illuminate\Auth\Authenticatable` + `HasApiTokens`), with its **own Sanctum guard**
(`customer`, alongside the existing `sanctum` guard for staff `User`s) registered in
`config/auth.php`. Both guards use Sanctum's shared, polymorphic token table (the
"shared infrastructure" Module 10 anticipates) — but because Sanctum's guard
resolves a token's `tokenable` polymorphically regardless of which named guard
checked it, **a bare `auth:sanctum`/`auth:customer` middleware check alone is not
sufficient to guarantee the resolved principal is the expected type**. Two new
middlewares close this gap explicitly:

- `EnsureCustomerPrincipal` — aborts 401 unless `$request->user()` is actually a
  `Customer` instance. Applied to every new B6 customer-facing route.
- `EnsureStaffPrincipal` — aborts 401 unless `$request->user()` is actually a `User`
  instance. **Applied retroactively to the existing B1-B5 staff route group** — this
  closes a latent gap that existed since Phase B1 (a Customer-issued token, once B6
  introduces them, could otherwise have been presented to staff routes and — if any
  future code assumed `$request->user()` was always a `User` without type-checking —
  caused undefined-behavior rather than a clean 401). No staff-side functional
  behavior changes; only an explicit type guard is added.

This is the same "defense-in-depth beyond what a single check provides" philosophy
already used for tenant isolation (ADR-001) and Super Admin access (Phase B1) —
applied here to the customer/staff principal boundary.

## Additional Critical Fixes Found During Implementation (Not Pre-Existing Bugs — Design Gaps Closed Before Being Committed)

1. **`ResolveTenantContext` had no case for a `Customer` principal at all** — it
   unconditionally called `$request->user()->activeStoreId()`, a method that only
   exists on `User`. Since B6 is the first phase where a non-`User` principal can
   ever authenticate, this would have been a fatal error the moment any customer
   route ran. **Fixed**: an explicit `instanceof Customer` branch resolves tenant
   context directly from `Customer.store_id` (a customer belongs to exactly one
   store, unlike multi-store staff).
2. **No tenant-resolution mechanism existed for ANONYMOUS (guest) requests at all**
   — the middleware's anonymous branch was an empty placeholder comment awaiting
   Module 19 (Domain Management). Guest cart/checkout (Module 11 §6) cannot function
   without resolving which store a guest is shopping at. **Fixed** with a documented
   interim mechanism: an `X-Store-Slug` header, resolved against the public
   `stores.slug` column — explicitly NOT treated as a security/authorization
   credential (a store slug is public information; the actual authorization boundary
   for any specific guest's cart remains that cart's own high-entropy `guest_token`).
   Revisit and replace when Module 19 provides real domain-based resolution.
3. **`auth:customer` middleware, applied naively to guest-accessible Cart/Checkout
   routes, would have rejected every guest with a 401** — Laravel's standard `auth:`
   middleware always aborts when its guard cannot resolve a user, which is correct
   for the authenticated-only Wishlist routes but wrong for Cart/Checkout, which
   Module 11 Final Rule #3 explicitly requires to support guests. **Fixed** by
   creating `AttemptCustomerAuthentication` (aliased `customer.optional`) — resolves
   a valid Customer bearer token if present (calling `Auth::shouldUse('customer')`
   so downstream `$request->user()` calls resolve correctly) but never aborts when
   none is present.
4. **Client-supplied guest cart tokens could have become a security weakness** — an
   early draft of `CartController::resolveCart()` would have let a client's
   self-chosen `X-Guest-Cart-Token` value become a NEW cart's real identifier if it
   didn't match an existing cart, undermining Module 11 §63's "non-sequential"
   (i.e., server-generated) requirement — an attacker could choose a guessable value
   and potentially collide with or "reserve" another guest's future token. **Fixed**
   before being left in the codebase: a client-presented token is only ever used to
   look up an existing cart; a NEW cart always receives a freshly generated,
   cryptographically random token, never the client's value.

## Scope Decision (Module 11 spans 105 sections — same discipline as B3-B5)

**B6 implements**: Cart + CartItem (server-side, provisional pricing per §13-14,
live revalidation, no premature inventory reservation), a minimal server-side
Wishlist (`WishlistItem`, one default wishlist per customer per §65, duplicate-safe,
authenticated-customer-only — guest wishlist is explicitly client-side/local-storage
per §69, not built server-side), a **one-shot Checkout** that performs final
validation and calls `OrderService::createOrder()` directly (no separate persisted
Checkout-Session state machine — see below), guest cart via a high-entropy token
(§63), cart merge on login (§22), the customer authentication boundary described
above, tenant isolation throughout, and a staff-safe regression fix
(`EnsureStaffPrincipal`).

**Explicitly deferred** (named so nothing is silently dropped):
- A persisted, multi-step **Checkout Session** state machine (§42-45: CREATED →
  ADDRESS_PENDING → SHIPPING_PENDING → PAYMENT_PENDING → REVIEW → PROCESSING →
  COMPLETED/EXPIRED/CANCELLED/FAILED) — this state machine exists specifically to
  coordinate a multi-step UI across Shipping (Module 13) and Payment (Module 12)
  gateway redirects, neither of which exists yet. B6's checkout is synchronous and
  one-shot (matching B5's own "auto-confirm, no payment gateway" precedent) —
  `Cart.status` transitions `Active → Converted` directly on success. Building a
  five-state session machine with nothing yet to coordinate would be exactly the
  "invent business rules... to make the system look complete" this and every prior
  milestone's prompt has warned against.
- Coupon/discount application (§29-31) — Module 14's scope; `Cart`'s total
  calculation has no discount line yet (matches B5's `discount_total_minor = 0`
  precedent).
- Tax calculation (§28) — no owning module exists yet; `tax_total_minor = 0`.
- Shipping address/method/calculation (§32-36) — Module 13's scope. `Cart` accepts
  an optional address snapshot for the (future) checkout review step but does not
  calculate shipping cost.
- Payment integration, tokenization, gateway callbacks (§37-41, §55-56, §78) —
  Module 12's scope entirely; not touched.
- Bundles/Digital/Service product special checkout handling (§18-20) beyond what
  Product's existing `ProductType` enum already schema-supports (Phase B3) — no
  type-specific cart behavior is added.
- Multiple wishlists, shared/gift/public wishlists (§65) — only the single default
  wishlist per customer is built.
- Abandoned-cart marketing/recovery workflows, cart analytics, conversion metrics,
  recently-viewed, saved-checkout-data (§72-77) — no consumer exists yet.
- Fraud/abuse/bot-protection foundations (§93-94) — inherits the platform's existing
  default rate limiting; a dedicated checkout-specific control is not built.
- Guest-to-registered account conversion with historical order linking (Module 10
  §9) — B6's guest checkout creates a guest `Order`/`Customer` snapshot exactly as
  B5 already supports; retroactively linking a guest's PAST orders to a newly
  registered account requires the verification workflow Module 10 §9 explicitly
  says is required ("avoid accidentally linking... based only on weak matching") —
  that verification flow is not built in B6.

None of these are abandoned — each is named so Phase B7+'s own Step 1 inspection
finds this documented list.
