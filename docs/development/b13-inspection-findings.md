# Phase B13 — Step 1: Inspection + Scope Decision (SEO & Content Management: Module 16)

## Inspection of Existing Code

- `Product`, `Category`, `Brand`, `Store` (B1/B3) — all already have a `slug`
  column, already enforced unique per store via each table's own migration-level
  unique constraint (verified by inspection). **B13 does not reinvent slug
  generation** — it adds the missing piece Module 16 actually requires on top:
  redirect preservation when a slug changes, reserved-word protection, and SEO
  metadata attachment.
- **No Module 19 (Domain Management) exists anywhere in this codebase** — `Store`
  has no custom-domain, verified-domain, or domain-status field of any kind.
  Module 16 §15 ("Domain Integration") and its own Non-Negotiable "canonical
  generation must use the correct verified storefront domain" cannot be
  implemented against a real per-tenant domain, because there is no domain to
  resolve. See "Architectural Decision — Canonical URL Base" below for how this
  gap is resolved without inventing Module 19.
- **This platform has no server-rendered storefront** (Module 05) — every prior
  phase (B6-B12) has been API-only, consumed by a separate frontend this
  repository does not contain. Module 16's storefront-integration language
  ("inject `<meta>` tags into rendered pages") does not have a literal target
  here. B13 resolves this by exposing SEO as a well-defined, resolved JSON
  contract via API (`SeoResolver`) that a future/external frontend renders into
  actual `<meta>`/`<link>` tags — the SAME "backend is authoritative, frontend
  renders" boundary already used by this milestone's own §8 ("keep server-side
  SEO resolution authoritative").
- No existing SEO/content/redirect/sitemap implementation exists anywhere in the
  repository — B13 is a clean addition.
- No HTML-sanitization library (e.g. HTMLPurifier) is present in `composer.json`
  and none can be installed in this sandbox (no Composer/network execution
  available) — see "Architectural Decision — Content Sanitization" below.
- No regressions found in B0-B12 during inspection. B13 adds no write path to
  `Product`/`Category`/`Brand`/`Order` or any other prior phase's authoritative
  table — every SEO/content record is a NEW, separate table referencing those
  entities by id only (Core Principle: "SEO stores SEO-specific configuration...
  does not duplicate the entire Product record").

## Architectural Decision — Canonical URL Base (Module 16 §10/§15-16, Domain Management Does Not Exist)

Since no verified per-tenant domain exists, canonical/sitemap/Open-Graph URLs are
built from a single, server-side-configured base URL
(`config('app.storefront_url')`, sourced from a new `STOREFRONT_BASE_URL` env
value — never the request's raw `Host` header, honoring Module 16's own explicit
Non-Negotiable) plus the store's own `slug` as a path segment:
`{STOREFRONT_BASE_URL}/{store_slug}/products/{product_slug}`. This is a
documented placeholder pattern — the moment a future phase adds real Module 19
domain verification, `SeoResolver`'s one canonical-URL-building method is the
single place to swap in the verified domain, without touching any caller.

## Architectural Decision — Content Sanitization (Module 16 §26/§35, Non-Negotiable, No Library Available)

With no HTMLPurifier/equivalent library installable in this sandbox, B13
implements `ContentSanitizer` — a conservative, documented, whitelist-only
sanitizer built on PHP's own `strip_tags()` plus a second pass that strips
`on*=` event-handler attributes, `javascript:`/`data:` URLs in `href`/`src`, and
`<script>`/`<iframe>`/`<object>`/`<embed>`/`<form>` tags outright, keeping only a
small allow-list of formatting tags (`p`, `br`, `strong`, `em`, `ul`, `ol`, `li`,
`a`, `h2`-`h4`, `blockquote`, `img` with `src`/`alt` only). This is explicitly
documented as a conservative minimum, not a full HTML-sanitization library — a
future phase with Composer/network access should replace it with a
battle-tested library (e.g. HTMLPurifier) without changing `ContentSanitizer`'s
call sites.

## Architectural Decision — SEO Resolution Hierarchy (Module 16 §7, Must Not Invent an Arbitrary Order)

Module 16 §7's own hierarchy is used verbatim: **Entity-specific SEO override →
Store default SEO → Generated fallback** (e.g. a Product's own name/description
when neither an override nor a store default exists). `SeoResolver::resolve()`
is the ONE place this hierarchy is evaluated — never duplicated across
controllers.

## Scope Decision (Module 16 spans 59 sections — same discipline as B8-B12)

**B13 implements**: a per-entity `SeoSetting` table (attachable to Product/
Category/Brand/Store, one row per entity via a `seoable_type`/`seoable_id`
polymorphic pair) carrying title/meta description/canonical override/OG fields/
robots directives, `SeoResolver` (the fallback hierarchy above), slug-change
redirect preservation (`Redirect` model + a small hook wired into
Product/Category/Brand's own slug-update path — additive, never rewriting their
existing update logic), a tenant-scoped `Redirect` engine (loop-prevention,
cross-tenant-destination rejection), sitemap generation (published/visible
content only, chunked if a store exceeds a single-file threshold), tenant-aware
`robots.txt`, structured data (JSON-LD) generators for Product/Category/
Organization/WebSite/BreadcrumbList, Open Graph metadata, one content entity —
**`ContentPage`** (static pages only: about/contact/policy-style pages) with
the Draft/Scheduled/Published/Unpublished/Archived lifecycle Module 16 itself
lists, server-side content sanitization, and staff-facing + public (sitemap/
robots/resolved-SEO) APIs.

**Explicitly deferred** (named so nothing is silently dropped, given this
module's 59-section scope and its own dependency on modules that don't exist):
- **Blog/Article foundation (§22)** — a materially larger content type
  (author, categories/tags, comments-adjacent concerns) than the static
  `ContentPage` B13 builds; Module 16 itself marks this "where entitled" —
  conditional, not mandatory, and no blog-specific package entitlement value is
  given to build against.
- **Landing Page Engine / Content Block Engine (§20-21)** — a materially more
  complex "build a page from reusable blocks" system; `ContentPage`'s own plain
  `body` field is the correct minimal seam a future phase can extend without
  restructuring.
- **Versioning & Revisions, Preview System (§24-25)** — no revision-history table
  or authenticated-preview-token mechanism is built; `ContentPage` is edited
  directly (draft state itself already prevents public visibility, satisfying
  Module 16's own Data Integrity Rule #9 without needing a separate preview
  system).
- **Multilingual & RTL (§27)** — no locale infrastructure of any kind exists
  anywhere in this codebase (B1-B12 have all been single-locale); building
  multilingual content here would mean inventing a locale system out of scope
  for Module 16 alone.
- **Custom HTML/head/script injection (§28)** — Module 16 itself calls this
  "privileged and safe" territory; allowing tenant-supplied raw `<script>`
  content is a materially higher-risk feature than everything else in this
  milestone, and the specification gives no concrete authorization/sandboxing
  model to build against. Deferred rather than built unsafely.
- **Search-engine verification tokens, analytics-integration foundation (§29-30)**
  — thin, low-risk features, but with no concrete provider list or verification
  mechanism specified; not invented.
- **SEO Health/Score Policy (§33-34)** — no scoring rubric is given; inventing
  one would violate this milestone's own "do not silently choose... document
  ambiguous metrics" discipline (borrowed from B12's identical concern).
- **Search index integration (§42)** — no Elasticsearch/Algolia/equivalent
  service exists anywhere in this codebase.
- **Import/Export, Backup/Restore specifics (§51-52)** beyond what the existing
  database backup story already covers — no dedicated SEO/content export format
  is defined precisely enough to build.
- **Real per-tenant verified domain / Module 19 integration (§15-16)** — see the
  Canonical URL Base decision above; the seam exists, the real domain
  verification does not.
- Admin/customer-facing UI (React components) — matches every backend-focused
  phase's own precedent.

None of these are abandoned — each is named so Phase B14+'s own Step 1
inspection finds this documented list.
