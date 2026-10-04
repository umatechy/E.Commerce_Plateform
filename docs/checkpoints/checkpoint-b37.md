============================================================
PHASE B37 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B37 — Images as files, not only addresses (Module 17 §6–7 brand
identity and media; Module 06 product images)

Owner request (2026-10-04): "jahan bhi logo ya products picture upload
k columns hen, wahan path dene ka option hy lekin choose file jpg, PNG
bna do".

Implementation Summary:

1. Store media — table `store_media` (migration 2028_09_01_000002),
   StoreMediaService, POST /api/v1/store/media (purpose logo, favicon,
   banner, social; throttle `store-media`). JPG and PNG only (no SVG),
   5 MB, size limits per purpose, re-encoded (metadata removed), stored
   under `stores/{store}/media/` with random names, audit entry.
2. Theme — logo, favicon, hero and banner images accept an https
   address or an upload of the same store; ThemeService refuses another
   store's file or a made-up path. The storefront now shows the
   favicon.
3. Admin — ImageUploadField (preview, address, "Choose file (JPG,
   PNG)", remove) on Theme → Branding (logo, favicon), home page hero
   and banner, and Content → SEO (sharing image, full address).
4. Add product — pictures can be chosen while creating a product; they
   are uploaded right after it is created (the product page already had
   uploads since B24).

Security: no SVG or other formats; every file decoded and re-encoded;
the theme can only reference its own store's uploads (tested with
another store's path, a forged path and a path traversal); theme images
need `theme.manage`, the sharing image `seo.manage` or `theme.manage`.

Tests (2026-10-04):
PHP: 1110 passed (StoreMediaTest 4). PHPStan: no errors.
Vitest: 176 passed (media.test.tsx 3). ESLint, TypeScript: clean.
Production build: passes.

Browser verification — EXECUTED (Chromium, local server, MFA on): 2 of 2.
1. New product created with two pictures chosen as files; both on the
   product page.
2. Logo and hero image chosen as files in Theme, published; the
   storefront header shows the logo (loaded, 400 px wide) and the hero
   shows its image. No console errors.

Not changed:
Brands and categories have no image field in the data model; nothing to
add a file to there.

Next: G11 (localization and Urdu/RTL), then G15.
