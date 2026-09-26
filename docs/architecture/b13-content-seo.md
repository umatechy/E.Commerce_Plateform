# Phase B13 — SEO & Content Management Architecture (Module 16)

See `docs/development/b13-inspection-findings.md` for the scope decision and two
critical architectural gaps this milestone resolves without inventing out-of-scope
infrastructure: no Module 19 (Domain Management) exists, and this platform has no
server-rendered storefront at all (API-only since B6).

## Core Principle: SEO Configures, Never Owns

`SeoSetting` never duplicates a Product/Category/Brand's own authoritative fields
— it is a separate table referencing the entity by id, carrying ONLY SEO-specific
overrides (title, meta description, canonical override, Open Graph fields, robots
directives). Confirmed by inspection: B13 adds zero new columns to `products`,
`categories`, `brands`, or `stores`, and modifies zero existing domain service.

## Canonical URL Base (No Module 19, Documented Placeholder)

Every canonical/sitemap/Open-Graph URL is built from one configured value
(`config('seo.storefront_base_url')`, sourced from `STOREFRONT_BASE_URL`) plus
the store's own `slug` — **never the request's raw `Host` header** (Module 16's
own explicit Non-Negotiable, honored by `SeoResolver::canonicalUrl()` never even
accepting a Request object as input). The moment a future phase adds real
per-tenant domain verification, this one method is the entire surface to update.

## SEO Resolution Hierarchy (Verbatim, Never Duplicated)

`SeoResolver` implements exactly Module 16 §7's stated order: **entity-specific
override → store default → generated fallback** (the entity's own name/
description). One method (`resolve()`) evaluates this for every SEO-able type —
`forProduct()`/`forCategory()`/`forBrand()`/`forContentPage()`/`forStoreHome()`
are thin, type-specific callers, never duplicated resolution logic.

## Content Sanitization (No Library Available, Documented Minimum)

With no HTMLPurifier-equivalent installable in this sandbox, `ContentSanitizer`
is a conservative, three-pass whitelist sanitizer: (1) `strip_tags()` against a
small allow-list (removes `<script>`, `<iframe>`, `<object>`, `<embed>`,
`<form>`, etc. outright), (2) strip every `on*="..."` event-handler attribute
regardless of quoting, (3) neutralize `javascript:`/`data:` URLs in `href`/`src`.
This is explicitly documented as a conservative minimum, not a production-grade
library — `ContentPageService` is the ONE call site, so swapping in a real
library later touches one class.

## Redirect Security (Open-Redirect and Loop Prevention, Structural)

`RedirectService::create()` rejects any destination matching an absolute-URL or
protocol-relative pattern BEFORE the redirect is ever stored — there is no code
path anywhere that can persist a `Redirect` pointing outside the current store's
own path space, which makes cross-tenant/open-redirect structurally impossible
here rather than merely checked for. A bounded (5-hop) loop-detection walk runs
before every create. Slug changes on `Product`/`Category`/`Brand` are recorded
via NEW, additive model observers (`ProductSlugObserver` etc.) — none of those
three domains' own update logic was touched.

## Sitemap and Robots.txt (Tenant Resolved from URL Path, Never Host Header)

`SitemapService::urlsFor()` only includes: active + publicly-visible products,
all categories/brands (excluded individually when marked `noindex`), and
Published-only content pages — draft/unpublished/archived content and anything
explicitly `noindex` never appears. `SeoPublicController` resolves tenant from
the explicit `{storeSlug}` URL path segment (a crawler sends no custom headers,
so this is the only safe, unambiguous mechanism — Module 16 §16's own explicit
Non-Negotiable against trusting `Host`).

## Structured Data (JSON-LD, Server-Generated Only)

`StructuredDataService` generates Product/Organization/WebSite/BreadcrumbList
schema.org objects purely from authoritative entity data (`Product`, `Store`) —
there is no code path anywhere that accepts or merges client-submitted JSON-LD.

## Content Lifecycle

`ContentPageService` is the sole writer of `ContentPage.status`
(Draft→Scheduled/Published/Archived→...→Archived terminal), mirroring every
other phase's state-machine pattern. `body` is unconditionally sanitized on both
create and update — no controller can bypass this.

## API Endpoints Added in B13

| Method | Path | Auth |
|---|---|---|
| GET/POST | `/api/v1/seo-settings` | staff (`seo.view`/`manage`) |
| GET/POST/PUT | `/api/v1/content-pages[/{id}]` | staff |
| POST | `/api/v1/content-pages/{id}/transition` | staff |
| GET/POST/DELETE | `/api/v1/redirects[/{id}]` | staff |
| GET | `/api/v1/public/seo/{storeSlug}/sitemap.xml`, `/robots.txt` | none |
| GET | `/api/v1/public/seo/{storeSlug}/products\|categories\|brands\|pages/{slug}` | none |
| GET | `/api/v1/public/seo/{storeSlug}/products/{slug}/structured-data` | none |

## UI

Not built in B13, matching every backend-focused phase's own precedent.

## Deferred (see inspection findings for the full, explicit list)

Blog/Article foundation, Landing Page/Content Block engines, versioning/
revisions, preview system, multilingual/RTL, custom HTML/head/script injection,
search-engine verification tokens, analytics-integration foundation, SEO health/
score policy, search index (Elasticsearch-style) integration, dedicated import/
export, real per-tenant verified domain integration, admin/customer-facing UI.
