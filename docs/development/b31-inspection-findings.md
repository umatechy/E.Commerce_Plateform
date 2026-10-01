# B31 — Inspection findings (gap G6, Admin UI)

Found while mapping the backend for the admin UI and while using the new
pages in a real browser. Each finding says what was wrong, how it was
found, and what was done. Findings 1–3 were defects that made existing
functionality unusable from any screen; they are fixed. The rest are
gaps that are documented, not papered over.

## Fixed

### 1. The API returned ids its own routes did not accept

Resources return a row's `public_id` (a ULID) as `id`. Routes bound the
numeric key. So a page that used the id it had been given got a 404. The
two actions the old pages had, order "Cancel" and stock "Adjust", could
not work. Tests did not notice because they call the routes with the
numeric `$model->id`.

Fix: `HasPublicId::resolveRouteBinding`. A ULID in the URL resolves by
`public_id`; a numeric key resolves as before. The query keeps the
model's global scopes, so a row of another store is still a 404
(`AdminUiTest::test_the_id_the_api_returns_works_in_the_url_and_another_stores_never_does`).

While there: a value that is neither a ULID nor a whole number is now a
404. Before, MySQL read `12abc` as `12` and answered with row 12 (inside
the tenant scope, so not a leak, but wrong).

### 2. No real store could create a product

`ProductController` requires the feature `products.basic` and its comment
says the package seeder provides it for all three tiers. The seeder did
not. Every test adds the entitlement itself, so the suite was green while
every registered store got a 403 on its first product.

Found by the browser check of the product form. Fix: `PackageSeeder`
seeds `products.basic` for Basic, Business and Premium, as the controller
documents. Covered by a test that registers a store against the real
seeder and creates a product.

This is not a new package rule. Module 04's feature matrix lists
"Products: YES / YES / YES" for Basic, Business and Premium and names the
key `products.basic`; the seeder had left it out. It is reported here
because it changes seeded data: an environment seeded earlier must run
`php artisan db:seed --class=PackageSeeder` again (it is idempotent).

### 3. A package could not be addressed

`PackageResource` returns `code` and no numeric key, and the update route
bound the numeric key. Fix: `Package::resolveRouteBinding` accepts the
code or the number.

### 4. Stock kept per variant had no product name

An inventory row of a variant has `product_id` null. The list could only
show "—". Fix: the resource names the variant's product.

## Added to the API (small, read-only or additive)

| Addition | Why the UI needs it |
|---|---|
| Product, order, inventory, payment, shipment list filters | The spec asks for search and filters (M06 §64, M09 §37); a page must not filter one page of rows in the browser |
| `internal_id` on product, variant, warehouse, promotion | Several write APIs take numeric keys (`inventory.product_id`, `orders.items.*.product_id`, promotion `target_ids`, `shipments.warehouse_id`, `coupons.promotion_id`, `campaigns.promotion_id`) while the resources only gave public ids. See finding 6 |
| Product `brand_id`, `category_ids` | Editing a product without knowing its brand and categories would silently drop them |
| Order `customer`, guest contact, addresses | M09 §37 "Admin Order View" lists them; the resource had only the guest's name |
| Payment and shipment `order`; payment balance | A payment or shipment could not be traced to its order, and an order page could not list its own |
| `GET /shipping/rates` | Rates could be set but never seen |
| `GET /permissions` | The role editor needs the permission catalog |

None changes an existing field or rule. All sit behind the existing
policies.

## Open (documented, not built)

### 5. Customers have no staff API

There is no endpoint to list or open customers (Module 10 is gap G7).
The Customers page shows the customer report and says what is missing.
The privacy tools (`/customers/{id}/personal-data`, `/erase`) exist in
the API but cannot be reached from a screen without a customer list;
they are left for G7 rather than exposed through a typed-in id.

### 6. Mixed id styles in the staff API

The API speaks public ids in URLs and resources but numeric keys in many
request bodies (finding list above). B31 exposes the numeric key as
`internal_id` so the pages can call the API as it is. The clean fix,
accepting public ids in request bodies, is an API contract change and
belongs to G13 (API contract quality).

### 7. An order created by staff cannot be paid or shipped

`POST /orders` creates the order and reserves stock but no payment
record. There is no staff endpoint to start or attach a payment, and
`ShipmentService` refuses an unpaid order. So an admin order can only be
cancelled. The "Create order" page and the order's Payments card say
this. Needs a backend decision: how staff record payment for a phone or
counter order.

### 8. A variant without its own price cannot be ordered

`ProductVariant::effectivePriceMinor()` does not fall back to the
product's price. The variant form and the variant list now say so. If a
fallback is wanted, it is a pricing rule change (Module 06 §25).

### 9. Endpoints that do not exist, so no control exists

- Attributes: no update. Shipping zones and methods: no update or delete.
  Warehouses: no delete. Segments: no update or delete. Promotions: no
  delete (archive by status). Notification templates: none once
  published.
- Promotion targets return ids only: products chosen earlier are shown as
  "Product #n".
- Team members carry no MFA status.
- Reservations have no list.
- Package entitlements cannot be edited through the API.
- Platform settings have no history endpoint.
- Theme has no draft preview.
- Super Admin "impersonate" returns the store and audits; there is no
  session switch to drive from a screen.
- Sitemap and robots have no settings.
- No global search.

### 10. Cross-currency sums

`/dashboard` and `/super-admin/dashboard` add amounts without regard to
currency. The store dashboard shows them in the store currency with a
note; the platform overview shows plain numbers with "not converted".

### 11. `alerts.critical_email_recipients` cannot be emptied

`SettingValidator` refuses an empty list, so once recipients are set the
list cannot go back to "all platform staff". The settings dialog says
that an empty list is not accepted.

### 12. The older tests still run with MFA and step-up off

Unchanged from B29 finding 1. The new tests switch Owner MFA on where
they test the gate; the browser checks ran with both on.
