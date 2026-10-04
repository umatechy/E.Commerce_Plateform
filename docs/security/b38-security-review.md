# B38 — Security review (localization)

Scope: `docs/architecture/b38-localization.md`.

## 1. Input and output

- The visitor's language comes from `?lang=`, a header or a cookie; only codes
  the store offers are ever used — anything else is ignored. The value never
  reaches a query, a path or HTML unescaped.
- Translations are plain text with length limits per field and only listed
  fields (`TranslationService::FIELDS`); product descriptions in another
  language go through the same `HtmlSanitizer` as the original. Theme
  translations use the theme validator (no links, numbers or icons can be
  changed through a translation; unknown languages refused).
- The interface dictionary is static code; placeholders are filled with
  `String.replace` and rendered by React as text.

## 2. Authorization and tenancy

- `PUT /translations/{type}/{id}` needs the item's own management permission;
  reading needs view permission. The item is looked up under the tenant scope
  (another store's item → 404); only product, category and brand types are
  routed.
- Translation rows carry `store_id` (`BelongsToTenant`); audit entry per change.
- Language settings use the existing settings permission and history.

## 3. Caching

Storefront cache keys include store, version and language, so one language's
page is never served for another. A changed or removed translation bumps the
store's cache version.

## 4. Request-state isolation (found and fixed)

The presenter kept the request language it was built with; with a controller
cached on the route (and in long-running workers) a later request could be
answered in an earlier visitor's language. The language is now read from the
request container on every use (findings F1, regression covered by
`LocalizationTest`).

## 5. Privacy

The Urdu font is bundled and served by the platform; no third-party font
request (checked in the browser). The language cookie holds only a language
code, `SameSite=Lax`, store path.

## 6. Residual

Emails and notifications are still English; an Urdu shopper receives English
messages (not a security issue; listed under not built).

Dependency audits 2026-10-04: `composer audit` — no advisories; `npm audit
--omit=dev` — 0 vulnerabilities (`@fontsource/noto-nastaliq-urdu` is CSS and
font files only).
