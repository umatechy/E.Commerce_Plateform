# Phase B15 — Step 1: Inspection + Scope Decision (Theme, Branding & Design System: Module 17)

## Inspection of Existing Code — Critical Finding

**No server-rendered storefront and no React storefront components exist
anywhere in this repository** — every phase since B6 (Cart/Checkout) has been
API-only, confirmed repeatedly in B10/B13/B14's own inspection findings. Module
17's entire premise (React components consuming CSS variables, rendered
`<meta>` tags, an admin theme editor) has no literal frontend target here. B15
therefore builds the BACKEND configuration layer — the theme registry, per-
store configuration with draft/publish separation, design-token/branding/
section validation, and the resolved-configuration API a future frontend would
consume — exactly the same "backend authoritative, frontend renders" boundary
already established by B13 (`SeoResolver`) and B14 (`DomainResolverService`).
No React components are built in B15, matching every backend-focused phase's
own precedent.

## Other Inspection Findings

- **No shared media/file-upload system exists anywhere in B0-B14** — no
  `Media` model, no upload controller, no storage-backed asset table was found
  by inspection. Module 17 §15's "reuse the existing media/storage
  architecture... do not create a second media system" cannot be honored by
  building a new one, since none exists to reuse. B15 therefore accepts logo/
  favicon as plain URL strings (validated as safe, non-executable references),
  NOT a file-upload feature — documented as deferred until a real, shared
  media system is built in a future phase, rather than B15 inventing its own
  second one (which the Non-Negotiable explicitly forbids).
- `Store` (B1/B3) already has `name` — reused directly as the brand name;
  B15 never duplicates it into a separate branding table field.
- `Package`/`EntitlementService` (B2) — reused directly for
  `theme.custom_css` gating; no new entitlement engine.
- B13's `SeoSetting`/`ContentSanitizer` and B14's `DomainResolverService` are
  both used as read-only dependencies (a future rendering layer would call
  all three), and neither is modified by B15 — confirmed no write path from
  this phase touches either domain.
- No regressions found in B0-B14 during inspection.

## Architectural Decision — Draft/Publish via One Row + an Append-Only Publication Ledger (Module 17 §16/§19-20, "Draft, Preview, Publish, Schedule, and Rollback")

Rather than a full version-history table for every field, B15 uses:
- **`StoreTheme`** — exactly one row per store, holding BOTH `draft_config`
  (mutable, edited freely) and `published_config` (only ever replaced
  atomically by the publish operation) as validated JSON blobs.
- **`StoreThemePublication`** — an append-only ledger (same 2-in-1 pattern as
  every append-only ledger since Phase B7's `PaymentTransaction`) recording
  every past `published_config` snapshot with who published it and when.
  **Rollback** is simply re-publishing an earlier ledger entry's snapshot —
  this gives real, working rollback without a dedicated version-numbering
  system. "Preview" is the draft config rendered in isolation (never affecting
  `published_config` until an explicit publish call) — no separate, expiring
  preview-token system is built, since draft/published are already two
  distinct, independently-readable fields on the same row (a staff member
  previews by requesting the draft explicitly, tenant-scoped and
  authorization-checked exactly like every other staff read).
- **Scheduled publishing** (§16's own list) is NOT implemented — no concrete
  cadence/timing model is given, and building a scheduler around a feature
  this milestone can already fully exercise synchronously (staff clicks
  Publish) would be speculative complexity Module 17 itself warns against
  elsewhere ("do not invent unnecessary complexity").

## Architectural Decision — One Validated Configuration Schema (Module 17 §13/§39, Non-Negotiable: "Do Not Accept Arbitrary JSON and Blindly Render It")

`ThemeConfigValidator` validates ONE combined JSON shape per store —
`{tokens: {...}, branding: {...}, sections: [...]}` — field by field, against
fixed whitelists:
- **Tokens**: exactly the color keys Module 17 itself lists (primary,
  secondary, accent, background, surface, text, muted, border, success,
  warning, error) — each validated as a strict hex color (`#RGB`/`#RRGGBB`
  pattern only, rejecting anything else, which structurally prevents CSS
  injection via a color value — no `url(...)`, no `expression(...)`, no
  `javascript:` can ever be a valid hex string) — plus `radius` (sm/md/lg
  enum) and `font_family`, itself checked against a small, hardcoded
  system-safe font whitelist (`system-ui`, `Inter`, `Roboto`, `Georgia`,
  `Playfair Display`, `Merriweather`) — **no arbitrary external font URL is
  ever accepted in B15's scope** (Module 17 §32's own explicit warning against
  "malicious remote font injection" is honored by not building URL-based
  custom fonts at all yet, not by attempting to sanitize one).
- **Branding**: `logo_url`/`favicon_url` (validated as syntactically-safe
  URLs, `http(s)://` only — never `javascript:`/`data:`), `tagline` (plain
  string, length-capped), `social_links` (a fixed whitelist of platform keys:
  facebook/instagram/twitter/tiktok/youtube, each itself URL-validated).
- **Sections**: `section_type` is a fixed enum (Module 17 §21-25's own list:
  announcement_bar, header, hero, featured_products, featured_categories,
  promotional_banner, newsletter, footer) — never a free-text component name,
  which structurally prevents "arbitrary component injection" (§19 Non-
  Negotiable) since there is no code path that maps an unrecognized string to
  anything at all. Each section has `position` (int), `is_visible` (bool),
  and a small per-type config object (also whitelisted per type, e.g. `hero`
  only accepts `heading`/`subheading`/`image_url`/`cta_url`).

An unrecognized key anywhere in the submitted JSON is REJECTED (422), never
silently dropped or blindly stored — satisfying "unknown configuration fields
should be rejected... according to the specification" with the stricter of
the two options Module 17 itself offers.

## Architectural Decision — Custom CSS: Minimal, Sanitized, Entitlement-Gated (Module 17 §18/§34, Explicitly Listed as an Objective, Also Explicitly Security-Sensitive)

Module 17 lists "safe custom CSS capabilities where entitled" as one of its
own primary objectives (§2.18), so — unlike B13's HTML content, where no
library existed and the safest choice was a broad, conservative whitelist
sanitizer — B15 implements ONE plain-text `custom_css` field per
`StoreTheme`, run through `CustomCssSanitizer` (same discipline as B13's
`ContentSanitizer`: strips `<script`, `javascript:`, `expression(`,
`@import`, `-moz-binding`, `behavior:` outright) before storage, and gated
behind a new `theme.custom_css` entitlement flag. **Decision on tiering**:
set `true` for Business and Premium, `false` for Basic — a genuine, reasoned
binary feature-gate consistent with this platform's existing three-tier
structure (Module 17 itself frames this as tier-differentiable: "where
entitled"), not an invented numeric limit (no count/size limit is added
beyond the flag itself). No CSS parser/sanitization library is installable in
this sandbox (the same constraint B13 documented for HTML) — this is a
conservative pattern-strip, not a real CSS-safety guarantee, and is stated as
such.

## Architectural Decision — No File-Upload / SVG Support Yet (Module 17 §15/§51-52)

Since no shared media system exists (see Inspection Findings), B15 does not
accept file uploads for logos/favicons at all — only URL strings are stored,
validated for safe scheme/format. **SVG is therefore never accepted as an
upload in B15's scope at all** (Module 17 §51's own permitted fallback: "if
SVG is not supported, reject it clearly" — honored by there being no upload
endpoint whatsoever, rather than an upload endpoint with incomplete SVG
sanitization).

## Scope Decision (Module 17 spans 82+ sections — same discipline as B8-B14)

**B15 implements**: a minimal platform `Theme` catalog (one seeded "default"
theme — no marketplace, no competing themes yet), `StoreTheme` (draft/
published separation, one row per store, auto-seeded with sensible defaults
for every new store via the established `StoreObserver` pattern),
`StoreThemePublication` (append-only publish history / rollback source),
`ThemeConfigValidator` (whitelisted tokens/branding/sections, strict hex-only
colors, whitelisted fonts, whitelisted section types), `CustomCssSanitizer`
(entitlement-gated, Business+Premium only), a `ThemeResolver` (the ONE
resolution point a future frontend/renderer calls — mirrors `SeoResolver`/
`DomainResolverService`'s exact pattern), publish/rollback lifecycle with
outbox events, and staff-facing + public (resolved-config) APIs.

**Explicitly deferred** (named so nothing is silently dropped, given this
module's 82+-section scope and its own dependency on modules that barely
exist):
- **Multiple/competing themes, a real theme marketplace (§7/§21 Module 27
  reference)** — only one seeded system theme exists; the `Theme` table is
  structured so a future phase can add more without restructuring
  (`StoreTheme.theme_id` already references it), but no second theme is built.
- **Theme versioning/compatibility validation between distinct theme
  versions (§8/§45)** — with only one theme and no version history for the
  theme DEFINITION itself (only for a store's own published CONFIGURATION,
  which is built), there is nothing yet to be incompatible with.
- **RTL/LTR, light/dark mode foundations (§9/§10 in the objectives list)** —
  no locale/theme-mode infrastructure exists anywhere in this codebase to
  attach these to; token STORAGE is mode-agnostic and could carry a future
  dark-mode variant set without restructuring, but no such variant is built
  now.
- **File upload for logos/favicons, SVG sanitization** — see Architectural
  Decision above; URL strings only.
- **Scheduled publishing** — see Architectural Decision above.
- **Header/Footer/Navigation systems, Product Card/Detail presentation,
  Button system, Iconography (§22-28+)** — these are FRONTEND COMPONENT
  concerns with no literal rendering target in this API-only platform (see
  Critical Finding); the underlying section-configuration data model
  (`sections` JSON) is schema-ready for a header/footer/nav "section type" to
  be added later without restructuring, but no actual header/footer/nav
  component or its own dedicated configuration schema is built in B15 beyond
  the generic section list already named.
- **Module 18 Animation/Interaction integration, Module 25 PWA integration** —
  neither Module 18 nor Module 25 exists anywhere in this codebase yet;
  nothing to integrate with.
- **Storefront interaction/analytics event emission (Module 17 §27's own "may
  emit approved interaction events")** — no storefront page exists to emit an
  event from.
- **Theme import/export (§38)** — no concrete file format/compatibility
  contract is given precisely enough to build safely.
- Admin theme-editor UI (React components) — matches every backend-focused
  phase's own precedent; the schema-validated, resolvable configuration API
  IS the deliverable a future editor would call.

None of these are abandoned — each is named so Phase B16+'s own Step 1
inspection finds this documented list.

## Bug Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

`ThemeService::createDefaultForStore()` originally only set `draft_config` for
a brand-new store, leaving `published_config` null — meaning `ThemeResolver`
would have had nothing to resolve for any store until a staff member
explicitly published once. This mirrors exactly the reasoning Phase B8's
`StoreObserver` already applied when seeding an ACTIVE (not draft) default
shipping zone for every new store. Caught before being left in the codebase —
fixed by auto-publishing the seeded default configuration immediately (both
`draft_config` and `published_config` set to the same validated default, with
`published_at` set), so every store has a real, resolvable theme from the
moment it exists.

## Second Bug Found and Fixed During Implementation — Potentially Catastrophic if Left In

`ThemeService::createDefaultForStore()` originally used
`Theme::query()->where('key', 'default')->firstOrFail()` to locate the seeded
system theme. `StoreObserver::created()` runs on **every single `Store`
creation across the entire platform**, including every existing test's
`Store::factory()->create()` call since Phase B1 (500+ test methods across
B1-B14). Since `ThemeSeeder` only runs via `DatabaseSeeder`/`php artisan
db:seed` — never automatically inside `RefreshDatabase`-based feature tests
(confirmed by inspection: no test calls `$this->seed()`, and no prior phase's
`StoreObserver` additions have ever depended on seeder-created data, always
using `firstOrCreate`/self-contained creation instead) — this would have
thrown `ModelNotFoundException` on **every single Store creation in the
entire test suite**, not just B15's own tests, silently breaking the whole
platform's test suite the moment this file was written. Caught immediately
upon reasoning through `StoreObserver`'s actual invocation pattern, before
being left in the codebase — fixed by using `firstOrCreate()` (self-healing,
matching every prior phase's own established pattern for anything
`StoreObserver` depends on) instead of `firstOrFail()`. `ThemeSeeder` still
exists and still runs via `DatabaseSeeder` for a real deployment's initial
setup, but the code path no longer HARD-DEPENDS on it having run first.

## Third Bug Found and Fixed — Caught During the Dedicated Security Review, Not Implementation

`StoreThemeController::updateDraft()` originally accepted and sanitized
`custom_css` without ever checking the `theme.custom_css` entitlement flag at
all — despite that flag existing, being seeded correctly (`false` for Basic,
`true` for Business/Premium), and being documented as this milestone's own
tier-differentiation decision. A Basic-tier store could therefore have set
custom CSS anyway, contradicting the documented package matrix. Found while
writing `docs/security/b15-security-review.md`'s own checklist item #28
("entitlement bypass"), before being left in the codebase — fixed by checking
`EntitlementService::assertFeatureEntitled('theme.custom_css')` inside the
controller whenever the request actually supplies non-empty `custom_css`
(ordinary token/branding/section edits remain available to every tier,
unaffected — the check never fires for a plain configuration update). This is
recorded as a distinct, honest example of a security-review pass catching
something implementation review missed, not merged into the earlier two
design-time findings.
