# B38 — Localization and Urdu / RTL (gap G11)

Specs: SRS LOC-001–006; Module 05 §39–41 (typography, RTL/LTR, language
support), §105–106 (RTL tests); Module 06 §101; Module 07 §99–100; Module 16
§27 (multilingual content, hreflang); Module 17 §8–9; Module 35 §4.3 (locale
fallback); Project Bible rule 114 (localization must not corrupt business
data).

## 1. Languages

`App\Domain\Settings\Services\Locales`: English (`en`, ltr) and Urdu (`ur`,
rtl, `ur-PK`). The architecture takes more (Module 05 names Arabic for later):
a language is added with its storefront dictionary and font.

Store settings:
- `store.languages` — the languages the storefront offers (default `[en]`);
  only offered codes, each once.
- `store.default_locale` — one of them (falls back to the platform default
  `en`). `ConfigService` refuses a default that is not offered and a list
  without the default.

## 2. The language of a visit

`StorefrontLocale` (scoped per request) is resolved in `ResolveStorefrontStore`
and, for the storefront's calls outside `/storefront` (cart, checkout,
account), in `ResolveStorefrontLocale` after the tenant is known. Order:
`?lang=` → `X-Storefront-Locale` header (sent by the storefront's own API
calls) → cookie `sf_lang` (set when `?lang=` is used, path of the store) →
store default. Only offered languages are used. Laravel's locale follows, so
validation messages come from `lang/ur/validation.php` where present
(English otherwise).

Components read the language from the container on each call, never keep it:
a controller cached on a route (or a long-running worker) must not answer in
an earlier visitor's language (found in testing, see findings F1).

## 3. Interface text

`resources/js/Storefront/i18n.ts`: `t('English text', {placeholders})`, with
the dictionary `Storefront/i18n/ur.ts` (≈ 400 entries: every storefront text
plus the labels of shared lists — order, payment, shipment, support and return
statuses, reasons, topics). A missing text shows in English. A Vitest test
fails when a text passed to `t()` anywhere in the storefront has no Urdu entry,
or when a placeholder is lost. Order numbers, SKUs and prices are never
translated (LOC-003); digits stay Western.

## 4. Translated content

Table `content_translations` (store, type, item, locale, field, value), model
`ContentTranslation`, `TranslationService`:

| Type | Fields |
|---|---|
| product | name, short description, description |
| category | name, description |
| brand | name, description |

API: `GET/PUT /api/v1/translations/{type}/{id}` (permission of the item:
`products.update`, `categories.manage`, `brands.manage`; item looked up in the
current store only; empty field removes the translation; audit entry).
`StorefrontPresenter` shows a translated field or the original; product lists
load their translations with one query (`cards()` / `prime()`). Cart and
wishlist lines use the translated name (`StorefrontText`).

Theme texts: a section's texts (headings, hero/banner, announcement, text
block, and the entries of testimonials, FAQ and trust badges) and the tagline
take `translations: {ur: {...}}` in the theme configuration (validated like
the originals; links, numbers, images and icons are never translated).
`StorefrontExperience` shows them for the visitor's language. Default section
headings ("New arrivals"…) are now chosen by the storefront in the visitor's
language when the owner sets none.

## 5. RTL and type

- `<html lang dir>` server-rendered and kept in step on client navigation; the
  storefront root carries `lang`/`dir`.
- Storefront components use logical CSS (start/end, ms/me, ps/pe, text-start)
  instead of left/right (20 places converted); directional arrows flip with
  `rtl:rotate-180`.
- Urdu text uses Noto Nastaliq Urdu, self-hosted (`@fontsource`, Arabic
  subset, loaded on Urdu pages only), with taller lines; Latin text and digits
  fall back to the theme font.

## 6. Search engines and caches

- Each language has its own address: `?lang=ur` (the default language has
  none). The canonical points to the page's own language; `hreflang` links for
  every offered language and `x-default`; `og:locale`.
- Storefront cache keys include the language; a translation change clears the
  store's storefront cache (model events — deletions go row by row so the
  cache hears of them).

## 7. Dates

Storefront dates use the store language's tag (`en-PK`, `ur-PK`) — LOC-004.

## 8. Admin

- Settings: "Default storefront language" and "Storefront languages".
- Product page: a Translations card; Categories and Brands: "Translate".
- Theme: Urdu fields under each home page section and for the tagline.
- The admin itself stays in English.

## 9. Demo store

`demo:store` (and `--refresh-look`) offers English and Urdu and carries Urdu
names, descriptions and home page texts for the demo catalogue.

## 10. Not built

Admin interface in Urdu; customer emails and notifications in Urdu (Module 21);
translated content pages, SEO titles/descriptions per language and localized
slugs (Module 16 §27 — generated titles use translated names); sitemap
language alternates; Urdu digits; translations of variant option names and
attributes (Module 07 §99 — attributes); Arabic; machine translation (Module 26).
