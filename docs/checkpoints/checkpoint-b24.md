============================================================
PHASE B24 CHECKPOINT
============================================================

Phase:
Development Phase B

Module:
B24 — Storefront (Module 05)

Implementation Summary:
The platform now has a storefront. Shoppers can:
- browse the home page (built from the store's theme sections);
- browse the catalog, with category, brand, price and stock filters,
  sorting and pagination;
- search, with suggestions;
- open product pages (image gallery, variant picker, stock status,
  related products) and content pages;
- use the cart (coupons) and a guest checkout.

The pages are served at /shop/{slug}, and at the root of a store's
verified custom domain. SEO is written into the HTML server-side:
title, description, canonical, robots, Open Graph and JSON-LD. The same
data is available as a public JSON API for headless and mobile
storefronts. Catalog responses are cached per store and invalidated
instantly by a version bump.

A live gate closes the storefront when the store is not launched, is
suspended, has a lapsed subscription, or the platform is in maintenance.
Stores used to stay "pending setup" forever; owners can now launch them
after a checklist. Products gained images; every upload is re-encoded,
which strips EXIF/GPS metadata.

Bugs fixed:
- A stored-XSS bypass in the B13 content sanitizer (replaced by a
  parser-based sanitizer).
- A 500 when the same variant was added to a cart twice.
- Checkout offered payment methods the store's package does not include.
- Unit tests were not part of the PHPUnit configuration.

New Components Implemented:
App\Domain\Storefront — StorefrontGate, StorefrontCatalog,
StorefrontPresenter, StorefrontExperience, StorefrontCache,
StorefrontSetupService, StorefrontCacheObserver, StorefrontPolicy,
ResolveStorefrontStore middleware, StorefrontApiController,
StorefrontWebController, StorefrontSetupController;
Catalog: ProductImage, ProductImageService, ProductImageController;
App\Support\HtmlSanitizer; config/storefront.php; root view SEO;
React: StoreLayout, ProductCard, ProductGrid, Price, Pagination,
SearchBox; pages Home, Catalog, Product, Page, Cart, Checkout,
Unavailable. See docs/architecture/b24-storefront.md.

Database Changes:
- New: product_images.

Permissions:
storefront.manage (PermissionSeeder; Owner only by default).

Automated Test Status:
EXECUTED.
- Backend: 829 tests, 2060 assertions — all passing on MySQL 8.0
  (44 new: 30 storefront feature tests, 14 sanitizer unit cases).
- Frontend: 16 Vitest tests passing (7 new); `npm run lint`,
  `tsc --noEmit` and `npm run build` pass.
- Static analysis: PHPStan/Larastan level 5 — no errors.

Runtime Verification Status:

| Area | Status |
|------|--------|
| Migrations up / rollback / up (MySQL 8.0) | EXECUTED |
| PHPUnit suite (MySQL 8.0, Redis) | EXECUTED — PASSING |
| PHPStan level 5 | EXECUTED — CLEAN |
| Frontend lint / typecheck / Vitest / Vite build | EXECUTED — PASSING |
| End-to-end in headless Chromium against php artisan serve: home → category → product → variant → add to cart → cart → checkout → order ORD-000001 placed | EXECUTED |
| Server-rendered SEO head on a real product page (curl) | EXECUTED |
| GitHub Actions CI run | NOT EXECUTED — runs on the next push to main/develop or a PR |
| Custom domain over real DNS/TLS | NOT EXECUTED — covered by Host-header tests only |

End-to-end notes:
- The first run returned 419 because the dev `.env` has
  SESSION_DOMAIN=localhost and the browser was on 127.0.0.1. The session
  domain must match the host shoppers use.
- The dev database's demo package had no entitlements; the real
  PackageSeeder grants every tier the ordering and payment features.

Known Limitations:
- No customer account pages (order history, addresses) or newsletter
  sign-up yet.
- No online card payment in the storefront (COD and bank transfer).
- No image renditions (resizing); no page CSP.

Recommended Next Milestone:
Module 34 (Support) or storefront customer accounts, at the product
owner's choice. Running the GitHub Actions workflow on a pull request
for B21–B24 should come first.
