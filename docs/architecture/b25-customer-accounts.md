# Phase B25 — Storefront Customer Accounts

## Layout

```
app/Domain/CustomerAccount/
  Models/CustomerAddress.php                   tenant-scoped; snapshot() for orders
  Services/StorefrontSession.php               cookie name, 30-day token, HttpOnly cookie
  Services/CustomerRegistration.php            shared by the token API and the web session
  Services/AddressBook.php                     rules, one default, limit of 20
  Services/CustomerOrderHistory.php            list/detail pinned to the customer
  Services/CustomerPasswordResetService.php    request/reset (hashed, single-use, 60 min)
  Http/Middleware/UseStorefrontCustomerSession.php  cookie → bearer (with X-Storefront-Request)
  Http/Controllers/StorefrontSessionController.php  login / register / logout
  Http/Controllers/CustomerAddressController.php
  Http/Controllers/CustomerOrderController.php
  Http/Controllers/CustomerProfileController.php    profile / email / password
  Http/Controllers/CustomerPasswordResetController.php
database/migrations/2027_12_01_000001_create_customer_addresses_table.php
database/migrations/2027_12_01_000002_create_customer_password_resets_table.php
resources/js/Storefront/{account,orders}.ts
resources/js/Components/Storefront/{AccountLayout,AddressForm}.tsx
resources/js/Pages/Storefront/Account/{Login,Register,ForgotPassword,ResetPassword,Dashboard,Orders,Order,Addresses,Profile,Wishlist}.tsx
```

## Session Flow

```
Login page ── POST /api/v1/storefront/session {email, password}
              (X-Store-Slug on /shop, X-Guest-Cart-Token → cart merge)
           ◀─ 200 customer + Set-Cookie: sf_session_{slug}=<token>; HttpOnly; SameSite=Lax; 30 days
Any page JS ─ fetch /api/v1/customer/... with X-Storefront-Request: 1 (cookie sent by the browser)
  api group: [Sanctum stateful → cookie decryption] → UseStorefrontCustomerSession
             (header present, no Authorization → Authorization: Bearer <cookie>)
           → auth:customer / customer.optional → ResolveTenantContext (customer's store) → controller
Sign out ─── DELETE /api/v1/storefront/session → token revoked, cookie cleared
```

Headless and mobile clients keep using `/customer/login` and a bearer
token directly.

## API (new)

Customer (`auth:customer`, `customer.principal`):

| Method | Path | |
|---|---|---|
| GET / PATCH | `/api/v1/customer/profile` | name, phone, marketing consent (consent changes audited) |
| PUT | `/api/v1/customer/email` | `email`, `current_password` (6/min) |
| PUT | `/api/v1/customer/password` | `current_password`, `password` (+confirmation); other sessions revoked (6/min) |
| GET / POST | `/api/v1/customer/addresses` | list (default first) / add |
| PATCH / DELETE | `/api/v1/customer/addresses/{address}` | own addresses only (else 404) |
| POST | `/api/v1/customer/addresses/{address}/default` | |
| GET | `/api/v1/customer/orders` | `page`, `per_page ≤ 50` |
| GET | `/api/v1/customer/orders/{orderPublicId}` | items, totals, addresses, payments, shipments |

Storefront (`storefront.store:api`):

| Method | Path | Limit |
|---|---|---|
| POST | `/api/v1/storefront/session` | 10/min |
| POST | `/api/v1/storefront/session/register` | 5/min |
| DELETE | `/api/v1/storefront/session` | signed in |
| POST | `/api/v1/storefront/password/forgot` | 5/min; always 202 |
| POST | `/api/v1/storefront/password/reset` | 10/min; 422 `invalid_token` |

Changed:
- `POST /api/v1/wishlist` also accepts `product` / `variant` public ids.
  Wishlist items carry `product_slug`, `variant_id`, `variant_options`
  and `image_url`.
- `CustomerAuthController::register` now uses `CustomerRegistration`.

## Pages (under `/shop/{slug}` or a custom domain root; all noindex)

| Path | Page |
|---|---|
| `/account/login`, `/account/register` | sign in / create account; `?redirect=` limited to this storefront |
| `/account/forgot-password`, `/account/reset-password?token=&email=` | password recovery |
| `/account` | overview: shortcuts, recent orders |
| `/account/orders`, `/account/orders/{id}` | order history, order detail with shipments |
| `/account/addresses` | address book (add, edit, default, delete) |
| `/account/profile` | details, email, password, "download my data" |
| `/account/wishlist` | wishlist (move to cart, remove) |

Also:
- the header has an "Account" link;
- product pages have "Save to wishlist" (guests are sent to sign in and
  back);
- checkout pre-fills a signed-in customer's details and saved addresses.

## Tests

Backend, 22 tests in `tests/Feature/CustomerAccount/`:

| File | Tests | Covers |
|---|---|---|
| `StorefrontSessionTest` | 6 | cookie flags, no token in body, header requirement, other store's cookie, logout, register, wrong password, guest cart merge |
| `CustomerAddressTest` | 4 | |
| `CustomerOrderHistoryTest` | 3 | |
| `CustomerProfileTest` | 3 | |
| `CustomerPasswordResetTest` | 3 | |
| `StorefrontAccountPagesTest` | 3 | account pages, redirect safety, variant wishlist regression |

Frontend: `resources/js/Storefront/account.test.ts` (3).

End to end in headless Chromium, with zero API errors:
1. A guest is sent to sign-in.
2. Register, and the HttpOnly cookie is not visible to JavaScript.
3. Add an address.
4. Save a variant to the wishlist and add it to the cart.
5. Check out with pre-filled details; the order appears in history and
   in detail.
6. Edit the profile.
7. Sign out (the cookie is cleared) and sign back in, with the redirect
   back to orders.
