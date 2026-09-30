# Phase B25 — Step 1: Inspection + Gap Analysis (Storefront Customer Accounts)

B24 gave shoppers a storefront, but only as guests. The customer API
(Module 10, B6) had:
- registration, login and `me`;
- a wishlist and notifications;
- shipment tracking for one order.

Nothing let a shopper see their orders, keep addresses, change a
password or recover one. There were no account pages at all.

## What Existed

| Area | State before B25 | Gap |
|---|---|---|
| Sign-in | Token API only (`/customer/login` returns a bearer token) | A browser storefront would have had to keep that token in JavaScript-readable storage, where any injected script can steal it |
| Orders | `GET /customer/orders/{id}/shipments` only | No order list or order detail for the customer |
| Addresses | None | Every checkout retyped the address |
| Profile | None | No way to change name, phone, email, password or marketing consent |
| Password reset | `password_reset_tokens` configured for customers but unused, and keyed by email alone | The same email is a separate customer in every store; a shared email key lets one store's request overwrite another's |
| Wishlist | Worked for simple products | Variant items were broken (below) |

## Bugs Found (fixed in B25)

| # | Bug | Found by | Fix |
|---|---|---|---|
| 1 | **Variant wishlist items lost their product.** They were saved with `product_id` NULL, so the wishlist showed them with no name and as "unavailable" | Reading `WishlistController` / `WishlistItemResource` | The product is always recorded; the resource falls back to the variant's product for existing rows. Test `test_wishlist_items_added_by_variant_keep_their_product` |
| 2 | **Nested throttles shared one counter.** A route `throttle:5,1` inside a group `throttle:120,1` uses the same per-IP key, so every request counted twice against both limits (the forgot-password limit tripped after 3 requests) | Password reset test (429) | Each stricter limiter has its own prefix (`throttle:5,1,sf-forgot`, …) |

## Design Decisions

### The session is an HttpOnly cookie, and only a special header unlocks it

`POST /api/v1/storefront/session` (and `/session/register`) issues a
Sanctum token like the token API does. The token is returned in an
HttpOnly, SameSite=Lax cookie, never in the body. So script running in
the page — including any that got past sanitization — cannot read it.

`UseStorefrontCustomerSession` turns the cookie into a bearer token only
when the request carries `X-Storefront-Request: 1`. Another site cannot
add a custom header to a cross-site request without CORS allowing it, so
a forged cross-site request is never authenticated (CSRF). Because the
cookie becomes an ordinary bearer token, every existing customer
endpoint works unchanged; there is no second authentication path.

Other session details:
- **Cookie name.** On the shared platform host (`/shop/{slug}`) the
  cookie is named per store (`sf_session_{slug}`). On a custom domain it
  is a host-only `sf_session`.
- **Expiry.** Tokens expire after 30 days.
- **Sign out.** Signing out revokes the token and clears the cookie.
- **Cart.** The guest cart joins the account on sign-in, as on the token
  API.

### Account pages carry no personal data in their HTML

The account pages (`/account/...`) render only the storefront shell;
each page loads its data from the customer APIs. So no personal data is
ever written into server-rendered HTML or its cache. Every account page
is `noindex, nofollow`.

The post-login `redirect` is accepted only as a path inside the same
storefront. Other sites, `//host` and `javascript:` values are dropped
(no open redirect).

### Addresses

The address book allows up to 20 addresses per customer. There is always
exactly one default while any address exists:
- the first address saved becomes the default;
- deleting the default promotes the most recent remaining address;
- adds lock the customer row, so concurrent requests cannot break the
  limit or the single default.

Orders keep their own address snapshots, so editing or deleting an
address never changes an order.

### Order history

`CustomerOrderHistory` pins every query to the signed-in customer (and,
through the tenant scope, to their store). A guest order placed with the
same email belongs to a different customer row and is not shown. The
detail view shows items, totals, the payment and the shipments. It never
shows internal ids, staff notes or cancellation actors.

### Profile and security

- **Email.** Changing the email requires the current password, and the
  address must be free among this store's registered accounts. Changing
  it clears email verification.
- **Password.** Changing the password requires the current one, uses the
  platform password policy, and signs out every other session.
- **Consent.** Marketing consent changes are audited (Module 32). The
  customer can download their data (the B22 export) from the profile
  page.

### Password reset (`customer_password_resets`)

The table is per store and per customer, not keyed by email.
- **No enumeration.** Every request is answered 202 with the same
  message. Only registered, non-erased accounts of this store get mail.
- **Tokens.** Tokens are 64 random characters; only their SHA-256 is
  stored. They expire after 60 minutes, work once, and a new request
  replaces older unused ones. A token from another store never matches.
- **Rate limits.** At most one mail per account per minute, on top of a
  per-IP limit of 5 per minute.
- **On success.** The customer is signed out everywhere, the email is
  confirmed (the link proved it), and the reset is audited.

### Logged-in checkout

The checkout page pre-fills the contact details and the default address.
The shopper can pick another saved address. Orders placed while signed
in appear under "My orders" (the existing checkout already attached
them to the customer).

## Out of Scope (deliberate)

- **Email verification flow.** Customers can shop unverified, as before.
  A reset confirms the address.
- **Cancelling an order or requesting a return** from the account. Order
  state changes stay staff actions.
- **Social login and two-factor authentication** for customers.
