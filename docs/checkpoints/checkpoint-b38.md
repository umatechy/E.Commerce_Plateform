============================================================
PHASE B38 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B38 — Localization and Urdu / RTL storefronts (gap G11; SRS LOC-001–006;
Module 05 §39–41; Module 06 §101; Module 07 §99; Module 16 §27;
Module 17 §8–9; Module 35 §4.3)

Owner request (2026-10-04): "phir g11 ka kam same passion men complete kro".

Starting point:
v1.1 at 1a94c68 (B37). No i18n; one language; left/right layout only.

Implementation Summary:
1. Languages: English and Urdu (Locales); store settings
   store.languages and store.default_locale with cross-checks.
2. Language of a visit: ?lang= → X-Storefront-Locale → cookie → store
   default; offered languages only; Laravel locale follows (Urdu
   validation messages, lang/ur/validation.php).
3. Interface: t() with an Urdu dictionary covering every storefront
   text and shared labels; English fallback; a test guards coverage.
4. Content translations: content_translations table, API
   GET/PUT /translations/{type}/{id} (product, category, brand);
   storefront shows translations with original fallback, batched;
   cart and wishlist names; theme section texts and tagline per
   language.
5. RTL: html lang/dir server-side and on navigation; logical CSS in
   the storefront; Noto Nastaliq Urdu self-hosted on Urdu pages.
6. SEO: own address per language (?lang=), canonical per language,
   hreflang + x-default, og:locale; caches per language.
7. Dates in the store language (en-PK / ur-PK).
8. Admin: language settings; Translations card on products,
   Translate on categories and brands; Urdu fields in the theme
   editor. Demo store offers Urdu with Urdu demo content.

Found and fixed: the presenter kept an earlier request's language
(route-cached controller) — now read per call; bulk deletes of
translations did not clear the cache — now row by row.

Tests (2026-10-04):
PHP: 1117 passed (LocalizationTest 7). PHPStan: no errors.
Vitest: 181 passed (i18n.test.tsx 5; two older tests now mock Inertia).
ESLint, TypeScript: clean. Production build: passes.
composer audit: no advisories. npm audit --omit=dev: 0.

Browser verification — EXECUTED (Chromium, local server, MFA on,
production build): 5 of 5.
1. Demo store: the "اردو" link opens the Urdu page: html dir=rtl
   lang=ur, Urdu interface and product names, Nastaliq loaded from the
   site, no third-party font request; the choice is remembered.
2. Server-rendered canonical with ?lang=ur, hreflang en/ur/x-default.
3. Add to cart from an Urdu card; the cart page and line are in Urdu.
4. 390 px phone, Urdu: no sideways scrolling.
5. New owner offers Urdu, translates a product on its page; the Urdu
   storefront shows the translation, the English one the original.
Screenshots of the Urdu home page (desktop and phone) were reviewed.

NOT EXECUTED — ENVIRONMENT LIMITATION:
- Screen readers in Urdu; browsers other than Chromium.

Not built: admin in Urdu; Urdu emails/notifications; content pages and
SEO fields per language; localized slugs; sitemap alternates; Urdu
digits; attribute/option translations; Arabic
(docs/architecture/b38-localization.md §10).

Next: G15 (catalog depth).
