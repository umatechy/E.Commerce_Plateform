# B36 — Security review (theme library, layouts, sections, motion)

Scope: `docs/architecture/b36-themes-and-motion.md`. Checked against Module 17
§57 and Module 18 §41 and the master prompt rules.

## 1. Configuration is data, never code

- Every key and value is on a whitelist (`ThemeOptions`,
  `ThemeConfigValidator`): unknown keys anywhere are refused; theme keys must
  exist; fonts come from a fixed list (no font URL can be stored); colours are
  hex; layout and motion values are enumerations; booleans must be booleans;
  section items have fixed fields with length limits; at most 30 sections.
- Texts (headings, testimonials, FAQ, text block) are stored as given and
  rendered by React as text — no `dangerouslySetInnerHTML`; a `<script>` in a
  text shows as text (test).
- Trust badge icons are names from a fixed list; the SVG paths are in the
  storefront code. Nothing uploaded is rendered as SVG.
- Motion is profiles and numbers resolved by the platform; no store-supplied
  JavaScript, easing string or CSS reaches the page (Module 18 §41). Custom CSS
  keeps the B15 sanitizer and its package feature.

## 2. Package enforcement (server side)

Saving, selecting, publishing and rolling back are checked by
`ThemeEntitlements` in `ThemeService`; the admin page only mirrors the result.
Publishing re-checks because a package may have changed. A downgraded store
keeps its data but is rendered within its package (resolver fallback). Tests
cover each refusal and the fallback.

## 3. Tenant isolation

Theme definitions are global platform data; store configuration stays in
`store_themes` (one row per store, resolved from the tenant context — no store
id from the client). Selecting or publishing in one store leaves another
unchanged (test). Best sellers count only the current store's order items
(tenant scope on `order_items`, column qualified with the table name).
Storefront caches stay keyed by store and version; a subscription change bumps
that store's version.

## 4. Privacy

Fonts are bundled and served from the platform; the storefront makes no
request to a font service (checked in the browser: no googleapis/gstatic
request). No analytics or fingerprinting was added for motion; reduced-motion
comes from the browser's own media query.

## 5. Permissions and audit

Selecting a theme needs `theme.manage`; publishing and rollback need
`theme.publish` (unchanged policy). Audit entries are written for selection,
draft changes, publishing and rollback, with the actor.

## 6. Rate limits

`POST /store/theme/select`: 30/min with its own key `theme-select`.

## 7. Residual risk / not covered

- Package edits made by platform staff on a package (not a store's
  subscription) do not clear storefronts at once; they take effect within the
  storefront cache time (10 minutes by default).
- Logos remain external https addresses (no upload/SVG sanitizer yet).

Dependency audits 2026-10-04: `composer audit` — no advisories; `npm audit
--omit=dev` — 0 vulnerabilities (the eight `@fontsource` packages are CSS and
font files only).
