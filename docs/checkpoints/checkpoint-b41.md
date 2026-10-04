============================================================
PHASE B41 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B41 — Attributes, attribute sets, specifications and category filters
(gap G15, part 3; Module 07 §18–19, §27–49, §75–79)

Owner request (2026-10-04): "jo abhi nhi bna or abhi ban skta he wo start
kr do" — continued with "resume".

Starting point:
v1.1 at 1518361 (B40, CI green). Attributes existed but were not linked to
products or categories; no storefront attribute filters.

Implementation Summary:
1. Attributes: group, unit, active, order; new colour type with colour
   codes; values edited in order, in-use values deactivated not deleted.
2. Attribute sets, applied to a category in one step.
3. Category attributes: required and filter flags; child categories
   follow their parent's list.
4. Product specifications: typed values, required ones enforced, shown on
   the product page; copied when a product is duplicated.
5. Storefront category filters: choices with counts (other filters
   applied), colour swatches, yes/no, number ranges; address state
   attr[key]=…, checked against the category; filtered pages noindex.
6. Admin: Attributes page (edit, values, colours, sets), Categories
   "Attributes & filters", product "Specifications" card. Urdu strings
   for the new storefront texts.

Found and fixed: StorefrontCatalog memos could outlive a request because
the services holding it sit in a route-cached controller — now
request-scoped (docs/architecture/b41-attributes-and-filters.md §4).

Tests (2026-10-04):
PHP: 1134 passed (AttributesAndFiltersTest 4). PHPStan: no errors.
Vitest: 189 passed (attributes.test.tsx 2).
ESLint, TypeScript: clean. Production build: passes.

Browser verification — EXECUTED (Chromium, local server, MFA on,
production build): 5 of 5.
1. New owner with two-step sign-in; category and 3 products.
2. Attributes page: RAM and a colour attribute with colour codes.
3. Categories: RAM required + filter, Colour filter.
4. Product page: saving without RAM is refused with the reason (the one
   console 422 is this deliberate refusal); with RAM it saves.
5. Storefront: RAM 8 GB shows 2 of 3, + Blue shows 1; counts and swatches
   shown; product page lists the specifications; 375 px phone fits.

Not built (b41-attributes-and-filters.md §6): package limits for
filters/taxonomy (no values in the specs); attribute translations;
attributes in CSV import/export; category templates; date/currency types;
unit conversion; display modes; filters on search pages.

CI: to be verified after push.
