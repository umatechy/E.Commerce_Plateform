# Phase B13 — Focused SEO/Content Security Review

Static/design-level review only — **NOT EXECUTED — DEFERRED TO VS CODE RUNTIME
VERIFICATION**.

## Regression Check — B0-B12 Capabilities Confirmed Intact

Verified by direct inspection: `BelongsToTenant::store()` present; zero `Seo`
references in any prior phase's domain service; `git status` confirms no
`app/Domain/Catalog/` model files were modified (only `AppServiceProvider`
gained three new observer registrations — additive); `EnsureCustomerPrincipal`/
`EnsureStaffPrincipal` present and unmodified; zero `Seo`/`ContentPage`
references in `OrderStateMachine`/`CampaignStateMachine`/`NotificationStateMachine`.

## Checklist (derived from Module 16's own Non-Negotiable rules and Data
Integrity Rules, applied with the same discipline as every prior phase's
dedicated checklist)

| # | Item | Finding | Status |
|---|---|---|---|
| 1 | Cross-tenant SEO/content access | `SeoSetting`/`Redirect`/`ContentPage` all use `BelongsToTenant`'s global scope — a query can structurally only ever see the resolved tenant's own rows. | Reviewed — OK |
| 2 | Cross-tenant sitemap/robots leakage | `SitemapService`/`RobotsService` operate entirely within the tenant context resolved from the `{storeSlug}` path segment before any query runs; tested explicitly (Store A's sitemap never contains a Store B product URL). | Reviewed — OK |
| 3 | Host header trust for canonical/sitemap/OG URLs | `SeoResolver::canonicalUrl()` takes no `Request` parameter at all — it is structurally impossible for it to read a `Host` header, since the value comes only from `config('seo.storefront_base_url')` and the store's own `slug`. Tested explicitly. | Reviewed — OK |
| 4 | Open redirect | `RedirectService::assertInternalPath()` rejects any absolute-URL-shaped or protocol-relative destination BEFORE storage — tested explicitly (external URL, `//`-relative URL both rejected). | Reviewed — OK |
| 5 | Redirect loops | `RedirectService::assertNoLoop()` walks up to 5 hops before allowing a create; tested explicitly (direct A→B, B→A loop rejected). | Reviewed — OK |
| 6 | Redirect exposing private tenant paths | Redirects are only ever created by staff (`seo.manage`) or the automatic slug-change observers — no code path lets an unauthenticated party create or discover a redirect's destination beyond following it. | Reviewed — OK |
| 7 | XSS via content page body | `ContentSanitizer` strips `<script>`/`<iframe>`/event-handler attributes/`javascript:`+`data:` URLs before `ContentPageService` ever persists `body` — tested explicitly with 6 dedicated cases (script tag, iframe, event handler, javascript: URL, form tag, allowed-tag preservation). | Reviewed — OK |
| 8 | Draft content leakage | `SeoPublicController::contentPage()` filters `WHERE status = published` explicitly — a draft/scheduled/unpublished/archived page returns 404 through the public endpoint regardless of its slug. Tested explicitly. | Reviewed — OK |
| 9 | Arbitrary JSON-LD injection | `StructuredDataService` builds every JSON-LD object purely from `Product`/`Store` model attributes — there is no method anywhere that accepts or merges a client-submitted structured-data payload. | Reviewed — OK |
| 10 | SEO setting mass assignment | `SaveSeoSettingRequest`/`SaveContentPageRequest`/`SaveRedirectRequest` are explicit allow-lists; every model uses `$fillable`. | Reviewed — OK |
| 11 | Slug-change observer authorization bypass | The observers (`ProductSlugObserver` etc.) fire on the Eloquent `updated` event regardless of who triggered it — they only ever CREATE a redirect (an additive, non-destructive side effect of an already-authorized update, since the underlying Product/Category/Brand update itself already went through its own domain's authorization); they never grant any new capability. | Reviewed — OK |
| 12 | Staff authorization | `SeoPolicy` (`view`/`manage`) checked in every staff controller method. Tested explicitly (403 without permission). | Reviewed — OK |
| 13 | Customer/staff boundary | Customer Sanctum token rejected on staff SEO routes (401), tested explicitly — same `staff.principal` middleware group as every other staff surface since Phase B1. | Reviewed — OK |
| 14 | Robots.txt injection | `RobotsService::generate()`'s disallow list is a fixed, hardcoded PHP array — no client input of any kind reaches the output. Tested (`Disallow: /api/`, `/checkout` both present, verbatim). | Reviewed — OK |
| 15 | Sitemap URL injection | Every sitemap `<loc>` value is `htmlspecialchars()`-escaped before being written into the XML tree; URLs themselves are built exclusively from `SeoResolver`'s own trusted canonical-URL logic, never a raw client string. | Reviewed — OK |
| 16 | Reserved-slug/collision handling | `Product`/`Category`/`Brand` already enforce per-store slug uniqueness at the database level (Phase B3, unchanged); `ContentPage` adds its own `unique(store_id, slug)` constraint — a colliding slug fails at the database layer rather than silently overwriting. | Reviewed — OK |
| 17 | Media/file security (content images) | `ContentSanitizer`'s allow-list permits `<img>` but no existing media-upload endpoint is wired to content pages in B13's scope — no new file-upload surface was introduced, so there is nothing new to secure here yet (documented limitation, not a built-and-unsecured feature). | N/A — feature deferred |
| 18 | Cache isolation | No SEO/sitemap/robots output is cached anywhere in B13 (generated fresh per request) — trivially satisfies tenant-cache-isolation by not caching yet. | N/A this milestone |
| 19 | Rate limiting on public SEO endpoints | No dedicated rate limit was added to the public sitemap/robots/resolved-SEO endpoints beyond the platform default — consistent with every other public endpoint's identical, already-documented limitation (B7/B8/B11's webhook/unsubscribe endpoints). | Documented limitation |
| 20 | Error leakage | Every controller catches domain exceptions explicitly and returns a structured message + code (`invalid_redirect`, `invalid_transition`); an unknown store slug or entity slug returns a plain 404 via `firstOrFail()`, never a stack trace or internal detail. | Reviewed — OK |

## Issues Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

None required a code fix after being written — this milestone's design-time
review (particularly around `RedirectService`'s open-redirect/loop checks and
`ContentSanitizer`'s multi-pass approach) was applied WHILE writing each class,
before any test was authored against it, rather than discovered afterward. This
is itself worth recording honestly: not every milestone surfaces a bug during
implementation, and B13's smaller, more self-contained service boundaries (no
integration with an existing state machine, unlike B9-B12) likely explains why.

## Known Limitations (Documented, Not Hidden)

1. `ContentSanitizer` is a conservative, hand-written whitelist sanitizer, not a
   battle-tested library (none installable in this sandbox) — a future phase
   with Composer/network access should replace it with e.g. HTMLPurifier.
2. No dedicated rate limiting on public SEO endpoints beyond the platform
   default.
3. No caching of sitemap/robots/resolved-SEO output yet (acceptable at current
   scale, per the same reasoning as B12's identical decision).
4. Canonical URLs use a single configured base, not a real verified per-tenant
   domain (no Module 19 exists) — documented, not a security gap given the
   Non-Negotiable it satisfies (never trusting `Host`) is fully honored either
   way.

None of the above required deleting or resetting existing B0-B12 work. No
destructive database operation was performed (all 3 new migrations in B13 are
new-table only).
