============================================================
PHASE B40 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B40 — Product import and export (gap G15, part 2; Module 06 §48–53)

Owner request (2026-10-04): "jo abhi nhi bna or abhi ban skta he wo start
kr do" / "ye krny ky bad B 40 ka kam start kr do in last push and commit".

Starting point:
v1.1 at 7cb8bd4 (B39, CI green). No product import or export.

Implementation Summary:
1. Import: CSV upload → parse → validate → preview → confirm → process
   → report. File never stored; checked rows encrypted in the cache for
   30 minutes, bound to store and staff member, confirmable once.
2. Matching: id, else SKU, else create — re-importing updates, never
   duplicates; variant rows by their own SKU under parent_sku/parent_id;
   empty cells keep current values on update.
3. Brands and categories (paths "Parent > Child") created when missing
   and listed in the preview; tags by name; hand-picked collections by
   name (with collections.manage).
4. Per row: permission (create / update), package product limit, sale <
   price, fixed status/visibility/type lists, currency; own transaction;
   skipped rows reported with reasons; one audit entry.
5. Export: same columns (edit and import back by id), variants as rows,
   category paths, tags, collections, picture addresses; cost price only
   with products.view_cost; formula cells neutralized (and restored on
   import); audited, throttled.
6. Admin: Export CSV and Import CSV on the Products page (example file,
   preview with problems, new brands/categories and limit notice, report).

Tests (2026-10-04):
PHP: 1130 passed (ProductImportExportTest 5). PHPStan: no errors.
Vitest: 187 passed (productImport.test.tsx 1). One theme test timed out
once while the PHP suite ran in parallel; it passed alone and in the full
rerun.
ESLint, TypeScript: clean. Production build: passes.

Browser verification — EXECUTED (Chromium, local server, MFA on,
production build): 5 of 5, no console errors.
1. New owner signs up with two-step sign-in.
2. Import CSV: preview shows 4 to add (2 of them variants), the new
   brand and categories, the bad row with its reason; 0 products saved.
3. Confirm: 2 products, 2 variants, brand, category path and tags; the
   list shows them.
4. The same file again: updated, still 2 products.
5. Export CSV downloads products, variants, options and category paths.

Not built (docs/architecture/b40-product-import-export.md §7):
package limits on imports/exports (no values in the specs — not
invented); background processing (synchronous, ≤ 2,000 rows); attribute
import (B41); pictures from addresses (deliberately not fetched).

CI: run on f54e1bf (B40 code) — success, verified 2026-10-04.

Next:
B41 — attribute sets, category filter configuration and faceted
storefront filters; then badges and featured ranking (Module 06 §36–37).
