# Phase B24 — Step 1: Inspection + Gap Analysis (Module 05)

Module 05 (Storefront) is what shoppers see. Phases B3–B23 built
everything behind it:
- catalog, stock, cart, checkout, payments and shipping;
- SEO, the theme engine and custom domains.

What was missing was the part that joins them. Earlier notes said so
directly: "This platform has no server-rendered storefront" (B13), and
B3 deferred caching "until a storefront exists".

## What Existed

| Area | State before B24 | Gap |
|---|---|---|
| Public catalog | Cart and checkout APIs only; products were added by internal id | A shopper could not list, search or open a product. Nothing published a product id to add |
| SEO (B13) | Resolved meta, sitemap and structured data as JSON for "a future frontend" | Nothing rendered them into a page |
| Theme (B15) | A validated, published config (tokens, branding, sections) | Nothing rendered it |
| Domains (B14) | Verified custom domains, and Host-based tenant resolution for API calls | No page answered on a custom domain |
| Store status | Registration created stores as `pending_setup` | Nothing ever moved a store to `active`, and nothing decided whether a store should be visible at all |
| Product images | None | A storefront without images |
| Content sanitizer (B13) | Regex-based | Bypassable (see below) |

## Bugs Found (fixed in B24)

| # | Bug | Found by | Fix |
|---|---|---|---|
| 1 | **Stored XSS in the B13 content sanitizer.** An unquoted `href=javascript:…` passed through unchanged. So did an entity-encoded scheme (`jav&#x09;ascript:`), which browsers decode and then run. Any content page rendered as HTML would have executed script on the storefront's origin, which is also the admin's origin | Direct probe of `ContentSanitizer` | New parser-based `App\Support\HtmlSanitizer`: DOMDocument with an element and attribute allow-list, and URL schemes checked after entity decoding and control-character removal. `ContentSanitizer` now delegates to it, and the storefront sanitizes again at render time. 14 unit cases (`tests/Unit/HtmlSanitizerTest.php`) |
| 2 | **Adding the same variant to a cart twice answered 500.** `CartService::addItem` looked up `product_id IS NULL` for variant lines, so it never found the existing line, and the insert then broke the unique `(cart, product, variant)` index | Probe test before building the storefront cart | Lookup by product id plus variant; the line now merges. Regression test `test_adding_the_same_variant_twice_merges_into_one_line` |
| 3 | **Stores could never go live.** Every registered store stayed `pending_setup` | Inspection | Launch checklist and `POST /api/v1/storefront/launch` |
| 4 | **Checkout offered payment methods the store's package does not include.** The shopper only found out when placing the order (403 "Your current package does not include [payment.cod]"), which also exposed the merchant's plan | End-to-end run in headless Chromium | The storefront offers only entitled methods, using the same keys `CheckoutService` enforces, and none at all without `orders.basic` |
| 5 | **`tests/Unit` was not in `phpunit.xml`.** Unit tests would never have run in CI | Adding the first unit test | A `Unit` test suite was added |

## Design Decisions

### One data layer, two front doors

`StorefrontExperience` assembles each view (shell, home, listing,
product, category, brand, page). Both of these read from it, so they
cannot disagree:
- the JSON API (`/api/v1/storefront/...`), for headless and mobile
  storefronts;
- the server-rendered Inertia pages.

### Which store is being visited

A storefront shows the store being visited, not the store of whoever is
signed in (a merchant browsing another shop must see that shop).
`ResolveStorefrontStore` therefore re-resolves `TenantContext` for
storefront routes only. It uses:
- the `{storeSlug}` path segment, for `/shop/{slug}`;
- a verified custom domain, for the domain root;
- for the API: Host, then `X-Store-Slug`, then the signed-in customer's
  store.

A customer token is valid only on its own store (403 `store_mismatch`).

### A live availability gate

`StorefrontGate` is evaluated on every request and never cached. A store
is open only when all of these hold:
- the platform is not in maintenance;
- the store is `active`;
- its subscription grants access.

This means a billing suspension (B23) closes the storefront at once, and
a payment reopens it at once. Before launch, the store's own staff can
preview it.

### Visibility semantics, in one place (`StorefrontCatalog`)

| Where | Product visibilities shown |
|---|---|
| Browse | `public`, `catalog_only` |
| Search | `public`, `search_only` |
| Product page | all three |
| Cart (unchanged rule) | `public` only (so `purchasable` is false otherwise) |

Hidden categories hide their whole subtree.

### Prices and stock in SQL

A product's price is its cheapest active variant's effective price (the
sale price when lower), or its own price when it has no variants. It is
computed in SQL, so price filters, price sorting and pagination agree.

Stock comes from the default warehouse. A product with no inventory
record is untracked and counts as in stock, matching `CartService`.
Exact counts are never published: only `in_stock`, `low_stock`,
`out_of_stock` or `backorder`.

### Versioned cache

`StorefrontCache` keys include a per-store version. An observer on every
model shoppers can see bumps that store's version. There are no wildcard
deletes and no effect on other stores, and it works on every cache
driver. Writes that bypass model events are bounded by the TTL, and cart
and checkout always re-check price and stock live.

### SEO without JavaScript

The root Blade view writes `<title>`, description, canonical, robots,
Open Graph and JSON-LD into `<head>` from the page's `seo` prop. The
`inertia` attribute lets the client-side `<Head>` take the tags over on
later page visits. JSON-LD is encoded with `JSON_HEX_TAG`, so product
names cannot close the script element. Filtered, sorted, paged and
search listings are marked `noindex, follow`; cart and checkout are
marked `noindex, nofollow`.

### Custom domains

The storefront routes are also registered on a domain group whose host
pattern excludes the platform's own host (from `APP_URL`), so the admin
routes are never shadowed. An unknown host gets a 404.

### Home page on the default theme

Every store starts with a default theme that has only a header and a
footer. Until the owner adds content sections, the home page shows
defaults (hero, categories, new arrivals) instead of an empty page.

### Option axes in a stable order

`option_values` is a MySQL JSON column, which does not keep key order.
Axes are therefore sorted by name; values keep variant order.

## Out of Scope (deliberate)

- Newsletter sign-up: the theme has a newsletter section, but no
  subscription endpoint exists yet, so the section is not rendered.
- Customer account pages (order history, addresses). The customer APIs
  exist; storefront pages for them are a follow-up.
- Online card payment in the storefront. Only COD and bank transfer are
  offered; `mock_redirect` is a test method.
- Image resizing into multiple renditions. Images are re-encoded at
  their original size.
- A page Content-Security-Policy (needs nonce support; see B22).
