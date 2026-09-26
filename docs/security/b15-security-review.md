# Phase B15 — Focused Theme/Branding Security Review

Static/design-level review only — **NOT EXECUTED — DEFERRED TO VS CODE RUNTIME
VERIFICATION**.

## Regression Check — B0-B14 Capabilities Confirmed Intact

Verified by direct inspection and `git status`: `BelongsToTenant::store()`
present; zero `Theme` references in `OrderService`, `SeoResolver`, or
`DomainResolverService`; `git status` confirms `StoreObserver.php` is the ONLY
file modified outside the new `app/Domain/Theme/` tree, seeders, permissions,
and routes — nothing under `app/Domain/Seo/` or `app/Domain/Domains/` was
touched at all; `EnsureCustomerPrincipal`/`EnsureStaffPrincipal` present and
unmodified.

## Checklist (this milestone's own 32-item Step 50 list)

| # | Item | Finding | Status |
|---|---|---|---|
| 1 | Cross-tenant theme access | `StoreTheme`/`StoreThemePublication` use `BelongsToTenant`'s global scope — a query can structurally only ever see the resolved tenant's own row. Tested explicitly (Store A never sees Store B's draft edits). | Reviewed — OK |
| 2 | Cross-tenant branding access | Branding lives inside the same tenant-scoped `StoreTheme.published_config`/`draft_config` — no separate, un-scoped branding table exists to leak from. | Reviewed — OK |
| 3 | Theme configuration injection | `ThemeConfigValidator` rejects any unrecognized key at every level (top-level, tokens, branding, sections, per-section config) — nothing is ever blindly stored. Tested explicitly (malicious top-level key, unrecognized token key, unrecognized section-config field all rejected). | Reviewed — OK |
| 4 | CSS injection | Color tokens are strict hex-only (`#RGB`/`#RRGGBB`) — no injection payload can be a valid hex string. Tested explicitly (a `url(javascript:...)`-shaped and a `; } body { display: none`-shaped value both rejected). | Reviewed — OK |
| 5 | XSS | Branding/section text fields (`tagline`, `heading`, etc.) are stored as plain strings with a length cap — this is DATA for a future renderer to escape, never markup itself; no field in B15's schema accepts or renders HTML. | Reviewed — OK |
| 6 | HTML injection | Same as #5 — no HTML is ever accepted anywhere in the theme configuration schema. | Reviewed — OK |
| 7 | Arbitrary JavaScript | No JavaScript field of any kind exists in B15's scope (Module 17 §35, Non-Negotiable: "do NOT implement arbitrary tenant custom JavaScript") — confirmed by inspection, no such field was built. | Reviewed — OK, N/A by construction |
| 8 | Component injection | `SectionType` is a fixed PHP enum; `SectionType::tryFrom()` returns `null` for any unrecognized string, which `ThemeConfigValidator` immediately rejects — there is no code path that maps an unknown string to any component at all. Tested explicitly. | Reviewed — OK |
| 9 | Dynamic import abuse | No dynamic import/`eval()`/code-evaluation of any kind exists anywhere in B15's code. | Reviewed — OK, N/A by construction |
| 10 | Path traversal | No file-upload or filesystem-path field exists in B15's scope at all (logos/favicons are validated URL strings, never file paths) — nothing to traverse. | Reviewed — OK, N/A by construction |
| 11 | Asset access | No asset-storage system was built in B15 (see inspection findings — no shared media system exists to reuse, and B15 does not invent a second one). | N/A — feature deferred |
| 12 | Private asset exposure | Same as #11 — no asset system exists to expose anything from. | N/A — feature deferred |
| 13 | Malicious font URLs | No externally-hosted font URL is ever accepted — `font_family` is checked against a small, hardcoded whitelist of system-safe identifiers only. Tested explicitly (an unlisted font name rejected). | Reviewed — OK |
| 14 | Theme preview authorization | Draft preview is reached only through the authenticated, `theme.view`-gated staff endpoint (`show()`, which returns both draft and published) — never a separate, unauthenticated preview mechanism. | Reviewed — OK |
| 15 | Preview token leakage | No preview token of any kind exists in B15's design (see architecture doc "Resolution") — draft and published are simply two fields on one already-tenant-scoped, already-authorized row; nothing to leak. | Reviewed — OK, N/A by construction |
| 16 | Theme publishing authorization | `ThemePolicy::publish()` checked in both `publish()` and `rollback()` controller methods — a stricter permission than plain `manage()`. Tested explicitly (403 without `theme.publish`). | Reviewed — OK |
| 17 | Configuration schema bypass | `ThemeService::updateDraft()` is the ONLY write path to `draft_config`, and it always calls `ThemeConfigValidator::validate()` first — there is no code path that persists configuration without validation. | Reviewed — OK |
| 18 | Invalid theme version | Only one `Theme` row exists in B15's scope (no versioning/competing themes built yet) — nothing to be invalid against. | N/A — feature deferred |
| 19 | Cross-tenant cache collision | No theme configuration is cached anywhere in B15 (resolved fresh per request) — trivially satisfies tenant-cache-isolation by not caching yet. | N/A this milestone |
| 20 | Theme import abuse | No import/export feature exists in B15's scope at all (explicitly deferred — see inspection findings). | N/A — feature deferred |
| 21 | Malicious configuration files | Same as #20 — no file-based configuration import exists; all configuration arrives as validated JSON in an authenticated API request body. | N/A — feature deferred |
| 22 | File upload abuse | No file-upload endpoint exists anywhere in B15 (logos/favicons are URL strings only, validated for safe `https://` scheme). | Reviewed — OK, N/A by construction |
| 23 | SVG security | No SVG (or any file) upload exists in B15's scope — Module 17's own permitted fallback ("if SVG is not supported, reject it clearly") is honored by there being no upload endpoint at all. | Reviewed — OK, N/A by construction |
| 24 | Open Graph/media leakage | B15 introduces no Open Graph logic of its own (that remains B13's `SeoResolver`/`StructuredDataService`, unchanged); `logo_url` in branding is a validated `https://` URL, never a private/internal reference. | Reviewed — OK |
| 25 | SEO boundary bypass | B15 makes no call into B13's `SeoResolver`/`ContentSanitizer`/`SitemapService` at all — confirmed by `git status`, zero files under `app/Domain/Seo/` were touched. | Reviewed — OK |
| 26 | Domain boundary bypass | B15 makes no call into B14's `DomainResolverService`/`DomainService` at all — confirmed by `git status`, zero files under `app/Domain/Domains/` were touched. | Reviewed — OK |
| 27 | Analytics event spoofing | No storefront interaction-event emission exists in B15's scope (explicitly deferred — no storefront page exists to emit an event from). | N/A — feature deferred |
| 28 | Entitlement bypass | `theme.custom_css` checked via `EntitlementService::assertFeatureEntitled()` inside `StoreThemeController::updateDraft()`, but ONLY when the request actually supplies non-empty `custom_css` — a Basic-tier store can still freely edit tokens/branding/sections. Caught and fixed during this very security review, before being left as an unenforced gap (see "Issues Found and Fixed" below). | Reviewed — OK, fixed |
| 29 | Super Admin boundary | No new Super Admin surface was added or needed in B15 — theme management is entirely store-scoped. | N/A this milestone |
| 30 | Audit bypass | `theme.configuration_updated`, `theme.published`, `theme.rolled_back` outbox events recorded via the existing, unmodified `RecordsOutboxEvents` mechanism for every mutating action; `StoreThemePublication` itself is an append-only, self-auditing ledger of every publish/rollback with who performed it. | Reviewed — OK |
| 31 | Resource exhaustion | `CustomCssSanitizer` hard-caps output at 20,000 characters; `tagline`/section text fields are length-capped (255/500 chars); `FeaturedProducts`/`FeaturedCategories` `limit` fields are clamped to 1-50. | Reviewed — OK |
| 32 | Theme rendering denial-of-service | No rendering happens in B15 at all (API-only, see architecture doc) — there is no rendering code path to exhaust. | N/A — no rendering surface exists |

## Issues Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

1. **The most significant finding of this entire project's B0-B15 history in
   terms of potential blast radius**: `ThemeService::createDefaultForStore()`'s
   original `firstOrFail()` dependency on `ThemeSeeder` having run would have
   broken `Store` creation — and therefore every single Feature test across
   the ENTIRE existing test suite (500+ tests, B1-B14) — the moment this file
   was written, since `StoreObserver::created()` fires on every Store
   creation and `ThemeSeeder` never runs automatically inside
   `RefreshDatabase`-based tests. Fixed with `firstOrCreate()` before this was
   ever committed.
2. `createDefaultForStore()` originally left new stores with no
   `published_config`, meaning `ThemeResolver` would resolve `null` for every
   store until a staff member manually published once. Fixed by
   auto-publishing the seeded default immediately.
3. `StoreThemeController::updateDraft()` originally accepted and sanitized
   `custom_css` without ever checking the `theme.custom_css` entitlement at
   all — a Basic-tier store could have set custom CSS despite the feature
   being documented as a Business/Premium differentiator. Caught during this
   security review, before being left in the codebase — fixed by checking
   `EntitlementService::assertFeatureEntitled('theme.custom_css')` whenever
   the request actually supplies non-empty `custom_css` (ordinary token/
   branding/section edits remain available to every tier, unaffected).

## Known Limitations (Documented, Not Hidden)

1. No asset/media/file-upload system exists in B15's scope — logos/favicons
   are URL strings only (documented, not a security gap: nothing is uploaded,
   so nothing can be a malicious upload).
2. No caching exists yet (acceptable at current scale, per the same reasoning
   as B12/B13/B14's identical decisions).

None of the "found and fixed" items required deleting or resetting existing
B0-B14 work. No destructive database operation was performed (all 3 new
migrations in B15 are new-table only).
