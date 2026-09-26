============================================================
PHASE B15 CHECKPOINT
============================================================

Phase:
Development Phase B

Module:
B15 — Theme, Branding & Design System (Module 17)

Implementation Summary:
Implemented the backend theme/branding configuration layer Module 17
requires, adapted to this platform's actual architecture: no server-rendered
storefront or React storefront components exist anywhere in B0-B14, so B15
builds the configuration/validation/resolution API a future frontend would
consume, mirroring the exact "backend authoritative, frontend renders"
boundary already established by B13 (SeoResolver) and B14
(DomainResolverService). A whitelisted configuration schema
(ThemeConfigValidator) structurally prevents CSS injection (hex-only colors),
arbitrary font injection (a small whitelist, no external URLs),  and
arbitrary component injection (a fixed section-type enum) - no arbitrary
JSON is ever blindly stored. Draft/publish separation with an append-only
publication ledger provides real rollback without a version-numbering
system. Runtime execution remains deferred to VS Code - nothing in this
milestone has been executed against a real PHP/MySQL runtime.

Bugs Found and Fixed:
1. POTENTIALLY CATASTROPHIC, caught before being left in the codebase:
   ThemeService::createDefaultForStore() originally used a firstOrFail()
   lookup depending on ThemeSeeder having already run. StoreObserver::created()
   fires on EVERY Store creation across the entire platform, including every
   one of 500+ existing tests' Store::factory()->create() calls since Phase
   B1, and ThemeSeeder never runs automatically inside RefreshDatabase-based
   tests - this would have broken Store creation, and therefore the entire
   existing test suite, the moment this file was written. Fixed with
   firstOrCreate() before this was ever committed.
2. createDefaultForStore() originally left a new store's published_config
   null, meaning ThemeResolver would resolve nothing until a staff member
   manually published once - inconsistent with Phase B8's own identical
   reasoning for seeding an ACTIVE default shipping zone. Fixed by
   auto-publishing the seeded default immediately.
3. Caught during the DEDICATED SECURITY REVIEW (not implementation):
   StoreThemeController::updateDraft() accepted and sanitized custom_css
   without ever checking the theme.custom_css entitlement flag, despite that
   flag being seeded correctly as a Basic/Business/Premium differentiator.
   Fixed by checking EntitlementService::assertFeatureEntitled('theme.custom_css')
   whenever the request actually supplies non-empty custom_css.

Architectural Decisions:
- Backend-only: no React components, no CSS-variable rendering, no admin
  theme editor UI - this platform has no literal frontend target for any of
  Module 17's rendering-layer language.
- Draft/publish via one StoreTheme row (draft_config, published_config) plus
  an append-only StoreThemePublication ledger - rollback is simply
  re-publishing an earlier snapshot, no dedicated version-numbering system.
- ThemeConfigValidator whitelists everything: colors must be strict hex
  (structurally prevents CSS injection), fonts against a small hardcoded
  system-safe list (no external font URLs accepted at all, per Module 17's
  own warning against malicious remote font injection), section types
  against a fixed enum (structurally prevents arbitrary component
  injection). Any unrecognized key anywhere is rejected outright, never
  silently dropped.
- Custom CSS is implemented minimally (Module 17 explicitly lists it as an
  objective) via a conservative pattern-strip sanitizer (no CSS-parser
  library is installable in this sandbox, same constraint B13 documented for
  HTML) and gated by a new theme.custom_css entitlement: false for Basic,
  true for Business/Premium - a genuine, documented tier-differentiation
  decision, not an invented numeric limit.
- No file-upload/media system exists anywhere in B0-B14, so B15 accepts
  logo/favicon as validated https:// URL strings only, never a file upload -
  documented as deferred rather than building a second media system (Non-
  Negotiable).
- Only one system Theme is seeded (no marketplace, no competing themes yet);
  the schema is structured so a future phase can add more without
  restructuring.

Theme Architecture:
Theme (platform catalog, one seeded row) -> StoreTheme (one row per store,
draft/published separation) -> StoreThemePublication (append-only rollback
ledger) -> ThemeResolver (the one resolution point a future renderer calls).

Theme Registry:
One system theme ("default", v1.0.0), seeded via ThemeSeeder AND self-healed
via firstOrCreate() in StoreService itself (never a hard seeder dependency).

Theme Versions:
Only the store's own CONFIGURATION has a version history (the publication
ledger); the theme DEFINITION itself has no version/compatibility model yet,
since only one theme exists.

Store Theme Configuration:
draft_config (freely editable) and published_config (only ever replaced
atomically by publish) as validated JSON blobs on one StoreTheme row per
store.

Design Tokens:
primary/secondary/accent/background/surface/text/muted/border/success/
warning/error colors (strict hex only), radius (sm/md/lg enum), font_family
(whitelisted system-safe fonts only, no external URLs).

Branding:
logo_url/favicon_url (validated https:// URLs only), tagline (length-capped
string), social_links (whitelisted platform keys, each URL-validated). Store
name is reused directly from the existing Store model, never duplicated.

Assets:
No file-upload system built - logos/favicons are URL strings referencing
externally-hosted assets, documented as deferred pending a real shared media
system.

Layouts / Sections / Components:
A fixed, whitelisted SectionType enum (announcement_bar, header, hero,
featured_products, featured_categories, promotional_banner, newsletter,
footer), each with its own small per-type config whitelist - never a
free-text component name, structurally preventing arbitrary component
injection.

Preview:
Draft configuration is readable through the authenticated, theme.view-gated
staff endpoint - no separate expiring preview-token system was needed, since
draft and published are simply two independently-readable fields on the
same already-tenant-scoped, already-authorized row.

Publishing:
Atomic: draft_config copied into published_config, a ledger snapshot
recorded, both inside one transaction. Requires the stricter theme.publish
permission (separate from theme.manage).

Theme Compatibility:
Not applicable with only one theme definition in scope - deferred alongside
multi-theme support itself.

B13 SEO Integration:
None - confirmed by git status that B15 touches nothing under
app/Domain/Seo/. Theme and SEO are parallel, independent configuration
layers a future renderer would consult separately, per Module 17's own
"integrate with, do not duplicate" instruction.

B14 Domain Integration:
None - confirmed by git status that B15 touches nothing under
app/Domain/Domains/. Theme receives the resolved Store/Tenant context;
it never determines tenant identity from a hostname itself.

B12 Analytics Integration:
None - no storefront interaction-event emission exists (no storefront page
exists to emit an event from); explicitly deferred.

B10 Marketing Integration:
None - Theme's section-configuration data model (e.g. promotional_banner) is
schema-ready to reference a Marketing campaign in a future phase, but no
such reference is built in B15; Marketing's own audience/segmentation/
campaign-state logic is never duplicated.

B11 Notification Integration:
None - no newsletter-signup-presentation or notification-preference UI is
built in B15's backend-only scope.

Database:
3 new migrations: themes (platform catalog, new table), store_themes (new
table, tenant-scoped, unique per store), store_theme_publications (new
table, append-only, tenant-scoped). No existing table's existing column
altered, renamed, or removed. No destructive operation performed.

Cache:
None implemented - theme resolution is computed fresh per request;
documented as an acceptable current-scale decision, consistent with B12/B13/
B14's identical reasoning.

Queue / Jobs:
None - draft update, publish, and rollback are all synchronous, staff-
triggered actions in this milestone's scope.

Events / Outbox:
theme.configuration_updated, theme.published, theme.rolled_back - all via
the existing, unmodified RecordsOutboxEvents mechanism.

Audit:
StoreThemePublication is itself an append-only, self-auditing ledger of
every publish/rollback action with who performed it; outbox events provide
the same for draft edits.

APIs:
GET/PUT /api/v1/store/theme[/draft], POST /api/v1/store/theme/publish, GET
/api/v1/store/theme/publications, POST
/api/v1/store/theme/publications/{id}/rollback (all staff); GET
/api/v1/public/theme/{storeSlug} (public, published-only).

UI:
Not built - matches every backend-focused phase's own precedent; the schema-
validated, resolvable configuration API IS the deliverable a future admin
editor would call.

Security Review:
Performed (docs/security/b15-security-review.md) - this milestone's own
32-item checklist reviewed end-to-end, plus a B0-B14 regression confirmation
(git status confirms only StoreObserver.php was modified outside the new
Theme domain, seeders, permissions, and routes - nothing under
app/Domain/Seo/ or app/Domain/Domains/ was touched at all). 3 issues found
and fixed - 2 during implementation, 1 during the dedicated security review
itself (the entitlement-bypass gap), each recorded honestly and distinctly
rather than merged together.

Tests Added:
44 new test methods across 6 Feature test files:
- tests/Feature/Theme/ThemeConfigValidatorTest.php - 14 methods
- tests/Feature/Theme/CustomCssSanitizerTest.php - 8 methods
- tests/Feature/Theme/ThemeServiceTest.php - 6 methods
- tests/Feature/Theme/ThemeResolverTest.php - 3 methods
- tests/Feature/Theme/ThemeAdminTest.php - 9 methods
- tests/Feature/Theme/ThemePublicTest.php - 4 methods
Plus 1 new model factory (StoreTheme). Combined with all carried-forward
B0-B14 tests: 550 test methods total across the whole suite (verified by
direct grep count, not estimated).

Tests Actually Executed:
NONE. No PHP, Composer, or MySQL runtime is available in this Claude App
sandbox.

Tests Not Executed:
All 550 test methods, including all 44 new to this milestone.

Static Inspections Performed (EXECUTED vs INSPECTED vs NOT EXECUTED - nothing
below was EXECUTED):
- Source inspection of every new/modified file against Module 17's
  requirements.
- A Node.js/Python-based brace/parenthesis balance check across all new/
  modified PHP files (one false-positive from a regex string literal in
  CustomCssSanitizer.php was independently verified as a false alarm via a
  string-literal-stripped recount, not a real syntax error).
- git status inspection confirming the exact, minimal blast radius of this
  milestone: only StoreObserver.php modified outside the new Theme domain,
  seeders, permissions, and routes.
- Route inspection: confirmed staff theme routes sit inside the
  staff.principal-guarded group, and the public theme route sits in the
  public group with no auth requirement.
- Reasoning-based inspection of StoreObserver's actual invocation pattern
  (every Store creation, including every test's factory call) that surfaced
  the potentially-catastrophic firstOrFail() dependency before any test was
  ever run against it.

Known Limitations:
- Nothing in this milestone has been executed against a real runtime.
- No asset/media/file-upload system exists in B15's scope - logos/favicons
  are URL strings only.
- No caching of theme resolution results yet.

Deferred Functionality:
Multiple/competing themes and a real theme marketplace, theme-DEFINITION
versioning/compatibility, RTL/LTR and light/dark mode foundations, file
upload for logos/favicons, SVG support, scheduled publishing, Header/Footer/
Navigation/Product-Card/Button/Iconography component systems, Module 18/25
integration, storefront interaction-event emission, theme import/export,
admin theme-editor UI. Full list with rationale in
docs/development/b15-inspection-findings.md.

Files Changed:
New: app/Domain/Theme/ (Models: Theme, StoreTheme, StoreThemePublication,
ThemeStatus, SectionType; Services: ThemeConfigValidator, CustomCssSanitizer,
ThemeService, ThemeResolver; Policies: ThemePolicy; Http/{Controllers:
StoreThemeController, ThemePublicController; Requests:
UpdateThemeDraftRequest; Resources: StoreThemeResource,
StoreThemePublicationResource}; Exceptions: 2 classes). New:
database/seeders/ThemeSeeder.php, 3 migrations, 1 factory, 6 test files.
Modified: DatabaseSeeder (+ThemeSeeder call), StoreObserver (+default theme
auto-creation), PermissionSeeder (+theme.view/manage/publish), PackageSeeder
(+theme.custom_css: false for Basic, true for Business/Premium),
StoreObserver permissions (+all 3 theme permissions for Manager),
routes/api_v1.php (+store theme routes), routes/api_v1_public.php (+public
theme route).

Git Status:
Verified by direct execution (git status) before this checkpoint was
written: all files listed above are new/modified/staged relative to the
previous commit (69edb67 / ae69918). No files outside the Theme domain, the
one additive StoreObserver line, seeders, permissions, and routes were
touched - confirmed zero changes under app/Domain/Seo/ or
app/Domain/Domains/.

Git Commit Status:
A commit for this milestone's work follows immediately after this
checkpoint; the real, executed commit hash is recorded via a follow-up
correction commit immediately after, same pattern used for every prior
phase's checkpoint.

Recommended Next Milestone:
Phase B16 - per the approved module sequence, Module 18 (Animation &
Interaction System) is the most direct dependency B15 itself named as
non-existent and therefore unintegrated; however, given Module 18 is a
purely frontend-rendering concern with even less backend surface than Module
17 had, Module 20 (Hosting & Infrastructure Management) or Module 30 (Umar
Techy Super Admin, already partially built since B1) may represent more
substantive backend work. Phase B16's own Step 1 should inspect the existing
SuperAdmin domain (app/Domain/SuperAdmin/) before deciding, since Module 30
already has a real foundation (impersonation, package/subscription
administration, and now domain suspension) that a dedicated Super Admin
phase could substantially expand without starting from zero.
