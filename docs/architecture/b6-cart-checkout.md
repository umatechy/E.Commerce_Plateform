# Phase B6 — Cart, Wishlist & Checkout Architecture (Module 11)

See `docs/development/b6-inspection-findings.md` for the scope decision and the
critical architectural decision on customer authentication.

## Customer Authentication Boundary

`Customer` (Phase B5 model) is now Sanctum-authenticatable via its own `customer`
guard (`config/auth.php`), structurally separate from staff `User`/`sanctum`. Two new
middlewares enforce the boundary explicitly, because Sanctum's guard resolves a
token's `tokenable` polymorphically regardless of which named guard checked it:

- `EnsureCustomerPrincipal` (`customer.principal`) — required on every customer-only
  route (Wishlist, `/customer/me`, `/customer/logout`).
- `EnsureStaffPrincipal` (`staff.principal`) — added retroactively to the existing
  B1-B5 staff route group.
- `AttemptCustomerAuthentication` (`customer.optional`) — for guest-accessible
  Cart/Checkout routes: resolves a valid Customer token if present (via
  `Auth::shouldUse('customer')`) but never aborts when absent, unlike the standard
  `auth:` middleware.

## Tenant Resolution for New Principal Types

`ResolveTenantContext` (ADR-001 Layer 1) gained two new branches this milestone:
- `Customer` principal → tenant is `Customer.store_id` directly (no multi-store
  switching concept for customers, unlike staff `User`s).
- Anonymous (guest) → an interim `X-Store-Slug` header, resolved against the public
  `stores.slug` column, until Module 19 (Domain Management) provides real
  domain-based resolution. **Not a security credential** — it only selects which
  tenant's public storefront to serve; a cart's `guest_token` remains the actual
  authorization boundary for that cart's contents.

## Cart

`Cart` + `CartItem` — tenant-owned, owned by either a `Customer` or a `guest_token`
(never both). `CartService` is the only writer. Adding an item validates the product
server-side (Module 11 §11: must be `Active`/`Public`) and computes the live price —
`price_at_add_minor` is stored only for **change detection**, never treated as
authoritative (Module 11 Final Rule #5).

**No inventory reservation happens at the cart level** (Final Rule and this
milestone's Step 6, both explicit) — `CartService::availableQuantity()` is a
read-only, informational check. Only `CheckoutService` → `OrderService` (Phase B5,
unchanged) ever calls `InventoryService::reserve()`.

### Guest Token Security

A client-presented `X-Guest-Cart-Token` is only ever used to **look up** an existing
cart. A brand-new cart is **always** issued a freshly generated, cryptographically
random token (`CartService::newGuestToken()`, 32 random bytes) — a client's own
invented value is never accepted as a new record's identifier, closing a
would-be-collision/guessing risk against Module 11 §63's "non-sequential" requirement.

## Wishlist

`WishlistItem` — authenticated-customer-only (guest wishlist is explicitly
client-side/local-storage per Module 11 §69, not built server-side). Duplicate-safe
via `firstOrCreate` against the table's own unique constraint (not a pre-check, which
would leave a race window). `WishlistItemResource` always reports **live**
availability (Module 11 §67) — never assumes a saved product stays purchasable.
Gated by the `wishlist.basic` feature flag (true for all three package tiers,
consistent with `orders.basic`'s precedent from Phase B5).

## Checkout — One-Shot, No Persisted Session (Documented Simplification)

`CheckoutService` is a thin orchestration layer: final validation
(`CartService::totals()`, reused, not duplicated) → translate cart items into
`OrderService::createOrder()`'s existing input shape → call it **unchanged** → mark
the cart `Converted` in the same outer transaction. No new pricing, inventory, or
order-creation logic exists in this class.

A full multi-step **Checkout Session** state machine (Module 11 §42-45:
CREATED→ADDRESS_PENDING→SHIPPING_PENDING→PAYMENT_PENDING→REVIEW→...) is **not**
built — it exists in the specification to coordinate Payment (Module 12) and
Shipping (Module 13) gateway redirects, neither of which exists yet. Building it now
would be inventing coordination for steps that don't exist, which every prior
milestone's discipline has avoided. `Cart.status` (`Active → Converted`) is B6's
sufficient state tracking.

## Transactional Consistency (Step 13)

`CheckoutService::checkout()` wraps `OrderService::createOrder()` (which has its own
internal transaction) AND the cart's `Converted` update in one **outer**
`DB::transaction()` — if marking the cart converted somehow failed, the just-created
order would roll back too (via Laravel/MySQL nested-transaction/savepoint semantics),
preventing "order created but cart still shows active" as a persisted inconsistency.

## Idempotency

Checkout reuses Phase B5's `orders.idempotency_key` mechanism entirely —
`CheckoutRequest` requires `idempotency_key`, passed straight through to
`OrderService::createOrder()`. No second idempotency layer was built. A retried
checkout with the same key returns the already-created order (200, not 201 — same
`wasRecentlyCreated`-based distinction established in Phase B5).

## Concurrency

No new mechanism — inherited entirely from B4 (`InventoryService`'s atomic
conditional `UPDATE`) via B5's `OrderService`. Two simultaneous checkouts for the
last unit of stock cannot both succeed. Tested via sequential simulation
(`CheckoutConcurrencyTest`), honestly labeled as such — genuine parallel-load
verification is deferred to VS Code/CI.

## Cart Merge (Module 11 §22)

On customer login, if an `X-Guest-Cart-Token` is presented, `CartService::mergeGuestCartIntoCustomer()`
sums matching-product quantities into the customer's own active cart (creating one if
none exists) and marks the guest cart `Merged` — never deleted, preserved as a
historical record (Module 11 §8).

## API Endpoints Added in B6

| Method | Path | Auth | Notes |
|---|---|---|---|
| POST | `/api/v1/customer/register` | none | rate-limited |
| POST | `/api/v1/customer/login` | none | rate-limited; merges guest cart if token presented |
| POST | `/api/v1/customer/logout` | `auth:customer` | |
| GET | `/api/v1/customer/me` | `auth:customer` | |
| GET/POST | `/api/v1/wishlist`, DELETE `/wishlist/{item}`, POST `/wishlist/{item}/move-to-cart` | `auth:customer` | |
| GET | `/api/v1/cart` | optional | guest or customer |
| POST/PUT/DELETE | `/api/v1/cart/items[/{item}]` | optional | |
| POST | `/api/v1/checkout` | optional | rate-limited |

## Frontend

Not built in this pass — B6's scope, given its size, prioritized the correctness of
the customer authentication boundary and the server-side commerce flow over a
storefront UI. Flagged as deferred work (see checkpoint), not silently dropped.

## Deferred (see inspection findings for the full, explicit list)

Persisted multi-step Checkout Session state machine, coupon/discount application,
tax calculation, shipping address/method/calculation, payment integration, bundle/
digital/service-specific checkout handling, multiple/shared wishlists, abandoned-cart
marketing, cart analytics, fraud/bot-protection foundations, guest-to-registered
historical order linking, storefront UI.
