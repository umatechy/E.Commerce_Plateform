============================================================
PHASE B13 CHECKPOINT
============================================================

Phase:
Development Phase B

Module:
B13 — SEO & Content Management (Module 16)

Implementation Summary:
Implemented an SEO/content configuration layer over the existing Product/
Category/Brand/Store catalog (B1/B3), resolving two structural gaps without
inventing out-of-scope infrastructure: no Module 19 (Domain Management) exists,
so canonical/sitemap/Open-Graph URLs use a single configured base URL rather
than a verified per-tenant domain; and this platform has no server-rendered
storefront (API-only since B6), so SEO is exposed as a resolved JSON contract
for an external frontend to render, not literal injected <meta> tags. Built a
whitelist-only content sanitizer in the absence of an installable HTML-
sanitization library. Runtime execution remains deferred to VS Code - nothing
in this milestone has been executed against a real PHP/MySQL runtime.

Bugs Found and Fixed:
None required a code fix after being written this milestone - the security-
sensitive pieces (RedirectService's open-redirect/loop checks,
ContentSanitizer's multi-pass approach) were designed correctly during
authorship, before any test was written against them, rather than discovered
and corrected afterward. Recorded honestly rather than fabricating a bug to
match prior phases' pattern.

Architectural Decisions:
- Canonical/sitemap/Open-Graph URLs are built from one configured
  STOREFRONT_BASE_URL value plus the store's own slug - never the request's
  raw Host header (Module 16's own explicit Non-Negotiable), and never a real
  verified per-tenant domain, since Module 19 does not exist.
- SEO resolution hierarchy is exactly Module 16's own stated order: entity-
  specific override -> store default -> generated fallback - never an
  invented precedence.
- Content sanitization is a conservative, hand-written three-pass whitelist
  sanitizer (strip disallowed tags, strip event-handler attributes, neutralize
  javascript:/data: URLs) - documented as a minimum, not a production-grade
  library, since none is installable in this sandbox.
- Only 4 SEO-able targets (Store/Product/Category/Brand) plus one content
  entity (static ContentPage) are implemented - Blog/Article, Landing Page,
  and Content Block engines are explicitly deferred as materially larger
  scope than this milestone's own static-page need.
- Redirect destinations are structurally restricted to internal, store-
  relative paths only - there is no code path that can ever persist an
  external or cross-tenant redirect destination.

SEO Domain:
SeoSetting (polymorphic seoable_type/seoable_id, one row per entity or a
single store-default row), SeoResolver (the one fallback-hierarchy
evaluator), Redirect (tenant-scoped, internal-path-only, loop-checked),
ContentPage (static pages, Draft/Scheduled/Published/Unpublished/Archived
lifecycle).

Slug Management:
Reuses Product/Category/Brand's existing per-store-unique slugs (Phase B3,
unchanged) - B13 adds only what was missing: automatic redirect recording via
new, additive model observers when a slug changes, without touching any of
those three domains' own update logic.

Redirect Management:
RedirectService enforces internal-path-only destinations, direct self-
redirect rejection, and a bounded 5-hop loop-detection walk before every
create - all tested explicitly.

Canonical URLs / Domain Integration:
Single configured base URL + store slug; documented placeholder pending a
future Module 19. Never derived from the Host header under any circumstance.

Sitemap:
Includes only active + publicly-visible products, brands/categories not
marked noindex, and Published-only content pages - draft/unpublished/
archived content and anything noindex is excluded. Tenant-isolated by
construction (resolved from the URL path segment, never a query parameter or
header).

Robots.txt:
Fixed, server-controlled disallow list (/api/, /customer/, /cart, /checkout,
/wishlist) plus a sitemap reference - no client input reaches the output.

Structured Data:
JSON-LD generators for Product, Organization, WebSite, and BreadcrumbList,
built purely from authoritative Product/Store data - never accepts client-
submitted JSON-LD.

Content Management:
ContentPage only (static pages) - Blog/Article/Landing-Page/Content-Block
engines, versioning/revisions, and a preview system are all explicitly
deferred (draft status itself already prevents public leakage, satisfying
Module 16's own Data Integrity Rule #9 without a separate preview mechanism).

Content Sanitization:
ContentSanitizer is the sole write path for ContentPage.body - strips
disallowed tags, event-handler attributes, and javascript:/data: URLs before
any content is persisted. Tested with 6 dedicated cases.

Database/Migration Summary:
3 new migrations: seo_settings, redirects, content_pages (all new tables,
tenant-scoped). No existing table's existing column altered, renamed, or
removed. No destructive operation performed.

API Summary:
GET/POST /api/v1/seo-settings (staff), GET/POST/PUT /api/v1/content-pages[/{id}]
+ POST .../transition (staff), GET/POST/DELETE /api/v1/redirects[/{id}] (staff),
GET /api/v1/public/seo/{storeSlug}/sitemap.xml, /robots.txt,
/products|categories|brands|pages/{slug}, and
/products/{slug}/structured-data (all public, tenant resolved from the URL
path).

Admin UI Summary:
Not built - matches every backend-focused phase's own precedent.

Storefront UI Summary:
Not built - no server-rendered storefront exists in this repository at all
(see Architectural Decisions).

Security Review:
Performed (docs/security/b13-security-review.md) - a 20-item checklist
derived from Module 16's own Non-Negotiable/Data-Integrity rules, reviewed
end-to-end, plus a B0-B12 regression confirmation (zero changes to any
Catalog model, only additive observer registrations in AppServiceProvider).
No design-time issues required fixing this milestone. 4 known limitations
documented (hand-written sanitizer in place of a library; no dedicated public-
endpoint rate limiting; no caching yet; no real verified domain integration).

Tests Added:
45 new test methods across 7 Feature test files:
- tests/Feature/Seo/ContentSanitizerTest.php - 6 methods
- tests/Feature/Seo/SeoResolverTest.php - 7 methods
- tests/Feature/Seo/RedirectServiceTest.php - 7 methods
- tests/Feature/Seo/SitemapServiceTest.php - 7 methods
- tests/Feature/Seo/ContentPageServiceTest.php - 5 methods
- tests/Feature/Seo/SeoAdminTest.php - 6 methods
- tests/Feature/Seo/SeoPublicTest.php - 7 methods
Plus 3 new model factories (SeoSetting, ContentPage, Redirect). Combined with
all carried-forward B0-B12 tests: 461 test methods total across the whole
suite (verified by direct grep count, not estimated).

Tests Actually Executed:
NONE. No PHP, Composer, or MySQL runtime is available in this Claude App
sandbox.

Tests Not Executed:
All 461 test methods, including all 45 new to this milestone.

Static Inspections Performed (EXECUTED vs INSPECTED vs NOT EXECUTED - nothing
below was EXECUTED):
- Source inspection of every new/modified file against Module 16's
  requirements.
- A Node.js-based brace/parenthesis balance check across all new/modified PHP
  files - no mismatches found.
- Route inspection: confirmed staff SEO/content/redirect routes are inside
  the staff.principal-guarded group, and all seo/{storeSlug}/... routes sit
  in the public group with no auth requirement.
- Regression inspection: confirmed via git status that no file under
  app/Domain/Catalog/ was modified - only AppServiceProvider gained three new
  observer registrations, and zero Seo/ContentPage references exist in any
  prior phase's state machine or domain service.
- Manual trace of RedirectService's loop-detection and open-redirect-
  rejection logic against the exact attack shapes Module 16 names (external
  URL, protocol-relative URL, direct A<->B loop, self-redirect).

Known Limitations:
- Nothing in this milestone has been executed against a real runtime.
- ContentSanitizer is a hand-written, conservative sanitizer, not a
  production-grade library.
- No dedicated rate limiting on public SEO endpoints beyond the platform
  default.
- No caching of sitemap/robots/resolved-SEO output yet.
- No real verified per-tenant domain integration (Module 19 does not exist).

Deferred Functionality:
Blog/Article foundation, Landing Page Engine, Content Block Engine,
versioning/revisions, preview system, multilingual/RTL, custom HTML/head/
script injection, search-engine verification tokens, analytics-integration
foundation, SEO health/score policy, search index (Elasticsearch-style)
integration, dedicated import/export, real Module 19 domain integration,
admin/customer-facing UI. Full list with rationale in
docs/development/b13-inspection-findings.md.

Files Changed:
New: app/Domain/Seo/ (Models: SeoSetting, Redirect, ContentPage, SeoableType,
RobotsDirective, ContentPageStatus; Services: ContentSanitizer, SeoResolver,
ResolvedSeo, RedirectService, SitemapService, RobotsService,
StructuredDataService, ContentPageService; Observers: ProductSlugObserver,
CategorySlugObserver, BrandSlugObserver; Policies: SeoPolicy;
Http/{Controllers: SeoSettingController, ContentPageController,
RedirectController, SeoPublicController; Requests: SaveSeoSettingRequest,
SaveContentPageRequest, SaveRedirectRequest; Resources: SeoSettingResource,
ContentPageResource, RedirectResource, ResolvedSeoResource}; Exceptions: 2
classes). New: config/seo.php, 3 migrations, 3 factories, 7 test files.
Modified: AppServiceProvider (+3 observer registrations), PermissionSeeder
(+seo.view/manage), PackageSeeder (+seo.basic for all 3 tiers), StoreObserver
(+seo permissions for Manager), routes/api_v1.php (+seo/content/redirect
routes), routes/api_v1_public.php (+public seo routes).

Git Status:
Verified by direct execution (git status) before this checkpoint was written:
all files listed above are new/modified/staged relative to the previous
commit (4788f9d / e4914ba). No files outside the Seo domain, the additive
Catalog observer registration, and documentation were touched.

Git Commit Status:
A commit for this milestone's work follows immediately after this checkpoint;
the real, executed commit hash is recorded via a follow-up correction commit
immediately after, same pattern used for every prior phase's checkpoint.

Recommended Next Milestone:
Phase B14 - per the approved 0-22 module sequence, every module through
Module 22 has now been implemented (B0-B13 span Modules 01/02-22 at varying
depth). The project's own remaining catalog (Modules 17-20, 23+, per the
Master Index) should be consulted for the next assignment; absent further
instruction, Module 17 (Theme, Branding & Design System) or Module 19
(Domain Management) are the most natural next candidates, since B13 itself
just identified Module 19's absence as a real, currently-unresolved gap
(canonical URLs use a placeholder base URL rather than a verified per-tenant
domain) that a future phase should close.
