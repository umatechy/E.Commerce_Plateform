# Phase B15 — Theme, Branding & Design System Architecture (Module 17)

See `docs/development/b15-inspection-findings.md` for the scope decision and
the critical finding that this platform has no server-rendered storefront or
React storefront components at all.

## Backend-Only, By Necessity

Module 17's own language (React components, CSS variables, rendered `<meta>`
tags, an admin theme editor) has no literal target in this API-only platform.
B15 builds the configuration layer a future frontend would consume — the same
"backend authoritative, frontend renders" boundary already established by
B13's `SeoResolver` and B14's `DomainResolverService`. Confirmed by `git
status`: B15 touches nothing under `app/Domain/Seo/` or `app/Domain/Domains/`
at all — only one additive line was added to `StoreObserver`.

## Draft/Publish via One Row Plus an Append-Only Ledger

`StoreTheme` — one row per store, `draft_config` (freely editable) and
`published_config` (only ever replaced atomically by publish). Publishing
also writes to `StoreThemePublication`, an append-only ledger (same 2-in-1
pattern as every append-only ledger since Phase B7's `PaymentTransaction`).
**Rollback** is simply re-publishing an earlier ledger snapshot — real,
working rollback without a dedicated version-numbering system.

## Whitelisted Configuration, Never Arbitrary JSON

`ThemeConfigValidator` is the single gate every configuration passes through.
Colors must be strict hex (`#RGB`/`#RRGGBB`) — structurally prevents CSS
injection via a color value, since no injection payload can ever be a valid
hex string. Fonts are checked against a small, hardcoded whitelist (no
external font URL is ever accepted — Module 17's own explicit warning against
"malicious remote font injection" is honored by not building URL-based custom
fonts at all yet). Section types are a fixed enum (`SectionType`) — there is
no code path that maps an unrecognized string to anything, which structurally
prevents "arbitrary component injection." Any unrecognized key anywhere in
the submitted JSON is rejected outright (422), never silently dropped.

## Custom CSS — the One Genuinely Higher-Risk Feature, Built Minimally

Module 17 explicitly lists "safe custom CSS... where entitled" as one of its
own primary objectives, so unlike deferring it entirely, B15 implements one
plain-text field sanitized by `CustomCssSanitizer` (same discipline as B13's
`ContentSanitizer` — a conservative pattern-strip, not a real CSS-parser
guarantee, since none is installable in this sandbox) and gated by a new
`theme.custom_css` entitlement: `false` for Basic, `true` for Business and
Premium — a genuine, documented tier-differentiation decision, not an
invented numeric limit.

## Two Bugs Found During This Milestone

1. **Potentially catastrophic, caught before being left in the codebase**:
   `ThemeService::createDefaultForStore()` originally used
   `Theme::query()->where('key', 'default')->firstOrFail()`. Since
   `StoreObserver::created()` runs on EVERY `Store` creation across the
   entire platform — including every one of 500+ existing tests'
   `Store::factory()->create()` calls since Phase B1 — and `ThemeSeeder`
   never runs automatically inside `RefreshDatabase`-based tests, this would
   have thrown `ModelNotFoundException` on every single Store creation in
   the whole test suite, not just B15's own tests. Fixed by using
   `firstOrCreate()` (self-healing, matching every prior phase's own
   established pattern for anything `StoreObserver` depends on).
2. `createDefaultForStore()` originally left `published_config` null for a
   brand-new store, meaning `ThemeResolver` would have nothing to resolve
   until a staff member explicitly published once — inconsistent with Phase
   B8's own identical reasoning for seeding an ACTIVE (not draft) default
   shipping zone. Fixed by auto-publishing the seeded default immediately.

## Resolution — Mirrors B13/B14 Exactly

`ThemeResolver::resolvePublished()` is the ONE place a future rendering layer
gets a store's live configuration — never the draft (Module 17 §10's own
Non-Negotiable: "a preview must not accidentally become production"). Staff
preview the draft only through the authenticated, authorized staff endpoint
(`resolveDraft()`), never through the public path.

## Entitlement and Authorization

`theme.custom_css` via the existing `EntitlementService` (no new entitlement
engine). `ThemePolicy` (`view`/`manage`/`publish`) — all three permissions
granted to the default Manager role (theme changes are cosmetic, not
financially/security-sensitive like `analytics.financial`/`domains.manage`,
so no withholding decision was needed here).

## API Endpoints Added in B15

| Method | Path | Auth |
|---|---|---|
| GET | `/api/v1/store/theme` | staff (`theme.view`) |
| PUT | `/api/v1/store/theme/draft` | staff (`theme.manage`) |
| POST | `/api/v1/store/theme/publish` | staff (`theme.publish`) |
| GET | `/api/v1/store/theme/publications` | staff (`theme.view`) |
| POST | `/api/v1/store/theme/publications/{id}/rollback` | staff (`theme.publish`) |
| GET | `/api/v1/public/theme/{storeSlug}` | none — published-only |

## UI

Not built in B15, matching every backend-focused phase's own precedent — the
schema-validated, resolvable configuration API IS the deliverable a future
editor would call.

## Deferred (see inspection findings for the full, explicit list)

Multiple/competing themes and a real theme marketplace, theme-DEFINITION
versioning/compatibility, RTL/LTR and light/dark mode foundations, file
upload for logos/favicons (no shared media system exists to reuse; URL
strings only), SVG support, scheduled publishing, Header/Footer/Navigation/
Product-Card/Button/Iconography component systems (frontend concerns with no
rendering target here), Module 18/25 integration (neither module exists),
storefront interaction-event emission, theme import/export, admin theme-
editor UI.
