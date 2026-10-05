============================================================
PHASE B43 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B43 — Product badges and featured ranking (Module 06 §36–37, §93;
Module 05 §19; Module 07 §17; Module 17 §8, §26)

Owner request (2026-10-04): "phir module 6 badges and features products ki
ranking py working complete kro. working best, professional, technical and
full thinking k sath high level kam ho."

Starting point:
v1.1 at 584e668 (B42, CI green).

Implementation Summary:
1. Badges, separated from product data (§36): automatic — sold out, sale
   (with % off for one price), only a few left (off by default), new,
   bestseller (units sold in the last days), featured — each switched and
   tuned in store settings; the store's own badges (label, theme colour,
   priority, shown, translatable). Ordered by priority, cut to the store's
   maximum, worked out per list with a fixed number of queries.
2. Storefront: badges on every card and the product page in the theme's
   colours; automatic labels in the visitor's language (Urdu included).
3. Ranking (§37, §93): products.sort_priority; "featured" order (featured,
   priority, newest, id — deterministic); default order from the category
   (Module 07 §17), else the store (Module 05 §19); search lifts featured
   products among equal matches (setting); home featured section and "You
   may also like" use the featured order; collections can sort "featured".
   Sort menu: Featured, Newest, Best selling, Price, Name (+ Most relevant
   on search, Recommended on collections); the listing reports its order.
4. Admin: Catalog → Badges; product sort priority and badges; category
   default order; settings; bulk add/remove badge and set priority; CSV
   sort_priority and badges; duplicates keep them.
5. Demo store: featured products, "Handmade" / "Eid special" badges with
   Urdu labels, featured as default order (also with --refresh-look; the
   local demo store was refreshed).

Found and fixed: the Translations panel showed the badge field as "label"
(no title) — found by the browser check; a slow theme UI test given an
explicit 15 s timeout (it exceeded 5 s only when the whole suite runs in
parallel on this machine).

Tests (2026-10-04):
PHP: 1141 passed (BadgesAndRankingTest 5); demo store tests rerun after
the demo change: 2 of 2. PHPStan: no errors.
Vitest: 194 passed (badges.test.tsx 4).
ESLint, TypeScript: clean. Production build: passes.

Browser verification — EXECUTED (Chromium, local server, MFA on,
production build): 5 of 5, no console errors.
1. New owner with two-step sign-in; Urdu offered; 3 shawls (one on sale).
2. Badges page: "Handmade" (green, priority 95) created and translated.
3. Product page: Silk Shawl featured, priority 9, Handmade badge.
4. Settings default order "featured": Silk Shawl first with Handmade + New;
   Pashmina "25% off"; sort menu shows Featured; Urdu page shows
   "ہاتھ سے بنا" and "25٪ رعایت".
5. Demo store products page: Featured / Handmade / New badges in the
   Boutique theme colours; 375 px phone fits.

Not built (b43-badges-and-ranking.md §5): per-category manual order;
rating/popularity badges and sorts (no reviews yet); badge schedules and
animation; package limits on badges (none in the specs).

CI: run on a94403c (B43 code) — success, verified 2026-10-04.
