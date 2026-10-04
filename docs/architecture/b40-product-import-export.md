# B40 — Product import and export (CSV)

Gap **G15**, part 2. Specs read before building: Module 06 §48 (import), §49
(staged validation), §50 (idempotency), §51 (export), §52–53 (package limits).
Same staged pattern as the customer import of B32.

## 1. Flow (§49)

```
Upload (POST /api/v1/products/import, multipart "file")
  → parse (header row names the columns; , or ; ; UTF-8 BOM allowed)
  → validate every row
  → preview (counts, row problems, new brands/categories, notices)
  → confirm (POST /api/v1/products/import/{id}/confirm)
  → process (each row in its own transaction)
  → report (created / updated / variants / skipped rows with reasons)
```

- The file is never stored. The checked rows wait **encrypted** in the cache
  for 30 minutes under a key bound to the store **and** the staff member; a
  preview can be confirmed once (`Cache::pull`), only by its author.
- Nothing is written before the confirmation.
- Limits: 2,000 rows, 4 MB; unknown columns are refused before any row is read.

## 2. Columns

`id, sku, name, type, status, visibility, short_description, description, price,
sale_price, cost_price, currency, brand, category, categories, tags, collections,
featured, parent_sku, parent_id, options, barcode, image_urls`

- Amounts are decimals in the row's currency (or the store currency), e.g.
  `4500`, `4,500`, `3999.50`; stored as minor units.
- `category` is the main category, `categories` more of them; both may be paths
  `Parent > Child` (up to 5 levels). Lists use `;` (or `|`).
- `collections`: names of existing **hand-picked** collections (needs
  `collections.manage`).
- Variant rows: `parent_sku` (a product of the file or the store) or
  `parent_id`, their own `sku`, and `options` such as `Size: M; Colour: Red`.
- `image_urls` is **export-only**: the import never fetches files from
  addresses in a file (no outgoing requests, no SSRF).

## 3. Matching and idempotency (§50)

| Row has | Result |
|---|---|
| `id` of a product of this store | update it (another store's id: "not found") |
| no id, `sku` of a product | update it |
| `sku` of a **deleted** product | refused (the SKU stays reserved) |
| neither | create |
| variant `sku` of a variant of the same parent | update it; of another product: refused |

On an update an **empty cell keeps the current value**. The same product or
variant twice in one file is refused on the later row. Re-importing a file —
or an exported file — updates and never duplicates.

## 4. Rules applied per row

- Permissions: a new product needs `products.create`; an update needs the
  product policy's `update` for that product; starting an import needs one of
  them (`ProductPolicy::import`). Cost prices only with `products.view_cost`
  (otherwise the column is ignored and the preview says so).
- Package limit (§53): before each new product that counts (`max_products`);
  status changes count like a single edit. Rows past the limit are skipped with
  the reason; the preview warns in advance.
- Sale price below price; type of an existing product unchanged; statuses and
  visibilities from the fixed lists; currency one of the platform's.
- Missing brands and categories are created (listed in the preview); tags are
  created by name (max 20, 60 chars).
- One row failing never undoes the others. One audit entry
  `products.imported` with the counts.

## 5. Export (§51)

`GET /api/v1/products/export?search=&status=` — the same columns (so it can be
edited and imported back by `id`), each product followed by its variants
(`parent_id`), category paths, tags, collections, featured, picture addresses.
Tenant-scoped, audited (`products.exported`), `cost_price` only for those who may
see it, formula-looking cells prefixed with `'` (and that `'` is removed again on
import). Throttled 10 per 10 minutes.

## 6. Admin

Products page: **Export CSV** (with the list's search and status) and **Import
CSV** (example file, check, preview with problems and new brands/categories,
confirm, report).

## 7. Not built / decisions

- No package entitlement gates import/export: Module 04 and Module 06 §52 name
  "Product Imports / Exports" only as *potential* limits, with no values. Not
  invented; the product limit is enforced. To be added when the owner sets
  limits.
- Processing is synchronous (≤ 2,000 rows) — no queue/background job yet.
- Attributes (§48 "Attributes") are not imported: attribute sets come in B41.
- Pictures are not imported from addresses (see §2).
