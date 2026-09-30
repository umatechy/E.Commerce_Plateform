# Phase B24 — Storefront (Module 05)

## Domain Layout

```
app/Domain/Storefront/
  Models/StorefrontAvailability.php     open | not_launched | unavailable | maintenance
  Services/StorefrontGate.php           live open/closed decision
  Services/StorefrontCatalog.php        every public catalog query (visibility, filters, SQL pricing/stock)
  Services/StorefrontPresenter.php      the public shape: cards, detail, tree, pages, SEO, JSON-LD
  Services/StorefrontExperience.php     assembles + caches each view; payment methods
  Services/StorefrontCache.php          per-store versioned cache
  Services/StorefrontSetupService.php   launch checklist + launch
  Observers/StorefrontCacheObserver.php bumps the store's cache version
  Policies/StorefrontPolicy.php         storefront.manage or Owner
  Http/Middleware/ResolveStorefrontStore.php  store resolution + gate (path | host | api)
  Http/Controllers/StorefrontApiController.php
  Http/Controllers/StorefrontWebController.php
  Http/Controllers/StorefrontSetupController.php
app/Domain/Catalog/Models/ProductImage.php
app/Domain/Catalog/Services/ProductImageService.php     re-encoding upload pipeline
app/Domain/Catalog/Http/Controllers/ProductImageController.php
app/Support/HtmlSanitizer.php                           parser-based allow-list sanitizer
config/storefront.php
resources/views/app.blade.php                           server-side SEO in <head>
resources/js/Storefront/{types,api,variants}.ts
resources/js/Components/Storefront/{StoreLayout,ProductCard,ProductGrid,Price,Pagination,SearchBox}.tsx
resources/js/Pages/Storefront/{Home,Catalog,Product,Page,Cart,Checkout,Unavailable}.tsx
```

## Request Flow

```
browser ──▶ /shop/{slug}/products/{p}         (or https://custom.domain/products/{p})
  web group: session, CSRF, ResolveTenantContext (visitor's own store, if staff)
  storefront.store:path|host
     ├─ find store (slug / verified domain) ─ none → 404 page
     ├─ TenantContext := visited store; forget {storeSlug}/{storefrontHost}
     └─ StorefrontGate ─ closed → 503 Storefront/Unavailable (staff preview before launch)
  StorefrontWebController ─▶ StorefrontExperience (cached per store version)
  Inertia page + `seo` prop ─▶ app.blade.php writes <title>/meta/canonical/JSON-LD

browser JS ──▶ /api/v1/cart, /shipping/quote, /checkout   (existing APIs)
  X-Store-Slug (path mode), X-Guest-Cart-Token (localStorage), X-XSRF-TOKEN
```

## Pages

| Path (under `/shop/{slug}` or a custom domain root) | Page | Robots |
|---|---|---|
| `/` | Home: theme sections in order (hero, featured categories/products, banner) | index |
| `/products` | Catalog: all products | index (noindex when filtered/sorted/paged) |
| `/search?q=` | Catalog: search results | noindex |
| `/categories/{slug}` | Catalog: category (subtree) | index |
| `/brands/{slug}` | Catalog: brand | index |
| `/products/{slug}` | Product: gallery, variant picker, availability, add to cart, related | index |
| `/pages/{slug}` | Content page (sanitized HTML) | index |
| `/cart` | Cart (live from the cart API; coupons) | noindex, nofollow |
| `/checkout` | Guest checkout (shipping quote, entitled payment methods, idempotent) | noindex, nofollow |

## API

Public (`customer.optional`, `storefront.store:api`, 120 requests/min):

| Method | Path | |
|---|---|---|
| GET | `/api/v1/storefront` | shell (store, theme, navigation), home sections, checkout payment methods |
| GET | `/api/v1/storefront/products` | `q, category, brand, min_price, max_price, in_stock, sort, page, per_page ≤ 48` |
| GET | `/api/v1/storefront/products/{slug}` | detail + related + SEO/JSON-LD |
| GET | `/api/v1/storefront/categories`, `/categories/{slug}` | tree with counts |
| GET | `/api/v1/storefront/brands`, `/brands/{slug}` | |
| GET | `/api/v1/storefront/pages/{slug}` | published pages only |
| GET | `/api/v1/storefront/search/suggest?q=` | categories + products (≥ 2 characters) |

Refusals: 404 `store_not_found` / `product_not_found` / …, 403
`store_mismatch`, 503 `storefront_not_launched` | `storefront_unavailable`
| `storefront_maintenance` (with `Retry-After`).

Staff (session): `GET /api/v1/storefront/setup` (checklist) and
`POST /api/v1/storefront/launch` (422 `setup_incomplete`, 409
`already_launched`); product images at
`GET|POST /api/v1/products/{product}/images`,
`PUT …/images/order`, `DELETE …/images/{image}`.

Cart (additive): `POST /api/v1/cart/items` also accepts `product` /
`variant` public ids; cart lines now carry `product_name`,
`product_slug`, `variant_id`, `variant_options`, `image_url`.

## Launch Checklist

| Key | Required | Done when |
|---|---|---|
| `products` | yes | an active, public product (or variant) with a price |
| `subscription` | yes | the subscription grants access |
| `warehouse` | no | a default warehouse exists |
| `shipping` | no | an active shipping method exists |
| `branding` | no | the published theme has a logo |
| `domain` | no | an active custom domain |

Launching sets the store `active`. It is audited (`storefront.launched`,
Module 32) and published as `store.launched` (outbox).

## Caching

Keys: `storefront:{store}:v{version}:{view}`. The version is bumped by
`StorefrontCacheObserver` on:
- Product, ProductVariant, ProductImage, Category, Brand;
- Inventory, Warehouse;
- ContentPage, SeoSetting, StoreTheme, StoreSetting, Domain, Store.

TTL: `STOREFRONT_CACHE_TTL` (600s). Suggestions, availability and
payment methods are never cached.

## Configuration

| Key | Env | Default |
|---|---|---|
| `storefront.cache_ttl_seconds` | `STOREFRONT_CACHE_TTL` | 600 |
| `storefront.per_page` / `max_per_page` | — | 24 / 48 |
| `storefront.low_stock_threshold` | `STOREFRONT_LOW_STOCK_THRESHOLD` | 5 |
| `storefront.images.*` | `STOREFRONT_IMAGE_DISK` | public disk; 12 per product; 5 MB; 100–6000 px |
| `seo.storefront_base_url` | `STOREFRONT_BASE_URL` | `{APP_URL}/shop` |

Deployment: `php artisan storage:link` (public images).
`SESSION_DOMAIN` must match the host shoppers use for `/shop` pages, or
the browser rejects the session and XSRF cookies (see the end-to-end
notes in the checkpoint).

## Tests

Backend, 44 new tests:
- `tests/Feature/Storefront/` (30):
  - `StorefrontCatalogApiTest` (11)
  - `StorefrontAvailabilityTest` (8)
  - `StorefrontWebTest` (5)
  - `ProductImageTest` (4)
  - `StorefrontCartTest` (2)
- `tests/Unit/HtmlSanitizerTest.php` (14 cases).

Frontend:
- `resources/js/Storefront/variants.test.ts` (3)
- `resources/js/Components/Storefront/Price.test.tsx` (4)

End to end, headless Chromium against `php artisan serve`:
home → category → product → variant → add to cart → cart → checkout →
order placed.
