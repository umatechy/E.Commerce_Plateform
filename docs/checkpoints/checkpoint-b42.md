============================================================
PHASE B42 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B42 — Attribute localization and specifications in CSV (open items of
B40/B41; Module 07 §38, Module 06 §48/§51)

Owner request (2026-10-04): "pehly local py run kro / phir jo kam nhi huy
wo kro / phir module 6 badges and features products ki ranking py
working complete kro".

Starting point:
v1.1 at 9a61f40 (B41, CI green).

Local run: MySQL and the app server started; migrations up to date;
production build; storefront, Urdu storefront, demo store and sign-in
pages answer 200 at http://127.0.0.1:8000.

Implementation Summary:
1. Attribute names and values in other storefront languages
   (TranslationService types attribute / attribute_value), read and saved
   together per language through GET/PUT /attributes/{id}/translations
   (values checked to belong to the attribute; attributes.manage).
2. Storefront filters and product specifications show the translations
   with the original as fallback; filter addresses keep the stable slugs.
   Translations are loaded in batches (two queries per list).
3. Admin: "Translate" on the Attributes page (name and every value, per
   language).
4. CSV: an `attributes` column, "ram: 16 GB; colour: Black; features:
   NFC, 5G; screen: 6.1; waterproof: yes" — attributes by key or name,
   values by text or slug, numbers and yes/no checked in the preview; the
   listed attributes are set and other specifications kept; a required
   specification missing undoes that row with the reason. The export
   writes the same column.

Tests (2026-10-04):
PHP: 1136 passed (AttributeLocalizationAndCsvTest 2). PHPStan: no errors.
Vitest: 190 passed (attributes.test.tsx 3).
ESLint, TypeScript: clean. Production build: passes.

Browser verification — EXECUTED (Chromium, local server, MFA on,
production build): 4 of 4.
1. New owner with two-step sign-in; Urdu offered; colour attribute and
   category filter. (Its early launch attempt answers 422 before any
   product exists — the one console error, intended.)
2. CSV with attributes: "Green" refused in the preview; 2 products imported.
3. Attributes → Translate: Urdu name and values saved.
4. Urdu category page: filter "رنگ" with "کالا" / "نیلا", choosing نیلا
   narrows to the right product with ?attr[colour]=blue; the product page
   shows کالا.

Not built: package limits for filters/taxonomy (no values in the specs);
category templates; date/currency attribute types; unit conversion.

CI: run on 584e668 (B42 code) — success, verified 2026-10-04.

Next: B43 — product badges and featured ranking (Module 06 §36–37, §93;
Module 05 §19; Module 07 §17).
