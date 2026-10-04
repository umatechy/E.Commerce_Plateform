============================================================
PHASE B39 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B39 — Catalog depth, part 1 (gap G15): collections, tags, featured and
related products, duplication, bulk changes, promotions aimed at a
collection (Module 05 §16; Module 06 §33–38, §46–47, §60; Module 14 §9)

Owner request (2026-10-04): "phir G15 catalogher kam sath sath commit and
push kro".

Starting point:
v1.1 at e779ac4 (B38, CI green). Products, categories, brands and
attributes only; no collections, tags, relations, duplicate or bulk tools.

Implementation Summary:
1. Collections: manual (ordered products), rule-based (fixed list of
   conditions, all/any), scheduled; live-only storefront page, API and
   listing filter; own order or newest / price / name / best selling;
   translatable name and description.
2. Tags: tenant-scoped, by name, up to 20 per product; tag pages
   (not indexed) and ?tag= filter.
3. Featured products: products.is_featured; the home "featured products"
   section can show the newest, the featured ones or a collection.
4. Related products: related, cross-sell, up-sell, alternative, ordered;
   product page shows them; the category fallback skips products already
   shown.
5. Duplicate: hidden draft copy, SKU/barcodes reset, content, categories,
   tags, collections, variants and image files copied; package limit
   checked.
6. Bulk changes on up to 500 products: 16 actions, per-product permission
   and limit checks, own transaction each, skipped list with reasons, one
   audit entry, delete confirmed in the admin.
7. Promotions: "collection" target (live membership, manual or rules);
   targets of every scope must now belong to the store.
8. Admin: Collections page, product list selection + bulk bar +
   Duplicate, product page Merchandising and "Shown with this product"
   cards, promotion collection target, theme section source.

Found and fixed during the phase: a separated docblock left
Product::variants() untyped (PHPStan); the product relation method is
productRelations() (Eloquent keeps loaded relations in $relations);
tags keep the first spelling typed; promotion targets were never checked
against the store (now checked).

Tests (2026-10-04):
PHP: 1125 passed (CollectionsAndMerchandisingTest 8). The first full run
had 3 backup tests fail only because the MySQL client tools were not on
PATH in that shell; rerun with them: 7 of 7 passed.
PHPStan: no errors.
Vitest: 186 passed (collections.test.tsx 5).
ESLint, TypeScript: clean. Production build: passes.
composer audit: no advisories. npm audit --omit=dev: 0.

Browser verification — EXECUTED (Chromium, local server, MFA on,
production build): 6 of 6.
1. New owner signs up with two-step sign-in, adds 3 products, launches.
2. Collections page: "Eid Picks" created, 2 products chosen in order.
3. Products list: all chosen, marked featured in one change ("3
   changed."); Duplicate creates "Leather Chappal (copy)" as a draft.
4. Product page: tags saved; "Goes well with" product chosen and saved.
5. Storefront: collection page lists Cotton Kurta then Silk Scarf (not
   the third product); product page shows "Goes well with"; an unknown
   collection answers 404.
6. 375 px phone: collection page and Collections admin do not scroll
   sideways.
Screenshots of the products list and the storefront product page were
reviewed.

Not built (docs/architecture/b39-collections-merchandising.md §8):
badge configuration (§36); featured in search ranking and category
sections (§37); bulk stock reference and export (§46); import/export
(B40); attribute sets and faceted filters (B41). Scheduled collections
follow the storefront cache lifetime (≤ 10 minutes).

CI: run on c726724 (B39 code) — success, verified 2026-10-04.

Next:
B40 — product import/export (staged upload → validate → preview →
confirm → process → report; SKU matching; package limits).
