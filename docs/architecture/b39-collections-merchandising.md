# B39 — Collections, tags, featured and related products, duplication and bulk changes

Gap **G15**, part 1. Specs read before building: Module 05 §16 (collections on the
storefront), Module 06 §33–38 (organisation, collections, tags, badges, featured,
related), §46–47 (bulk operations and their safety), §60 (duplication),
Module 14 §9 (promotions aimed at a collection).

## 1. Data

Migration `2028_11_01_000001_catalog_collections_tags_relations`:

| Table / column | Purpose |
|---|---|
| `collections` | Tenant-scoped, public id, soft delete. `type` manual or rule; `rules` (JSON) and `match` (all/any) for rule-based ones; `sort`; `status` draft/active; `is_visible`; `starts_at` / `ends_at` (schedule); `sort_order`. Slug unique per store. |
| `collection_product` | Hand-picked products of a manual collection with `position`. |
| `tags`, `product_tag` | Tenant-scoped tags (slug unique per store), at most 20 per product. |
| `products.is_featured` | Module 06 §37. |
| `product_relations` | `related`, `cross_sell`, `up_sell`, `alternative`, ordered, both products of the same store. |
| permission `collections.manage` | Administrator, Manager and Content & Marketing roles (Owner implicitly). |

Mapping of the blueprint's relation names (§38): *Similar* → `alternative`;
*Frequently bought together* → `cross_sell` ("Goes well with"). No separate types
were added; the mapping is shown in the admin hints.

## 2. Collections

- **Live** = active, visible and inside its schedule (`Collection::scopeLive`).
  Anything else has no page (404) and, as a filter, lists nothing — never
  everything.
- **Rule-based**: `CollectionRules` accepts only a fixed list of conditions —
  category (with its children), brand, tag, price at least / at most (minor
  units), on sale, in stock, featured, published within N days. Never a column
  name from the request. Ids are checked in the store (tenant scope).
  At most 10 conditions. The conditions run as SQL in
  `StorefrontCatalog::applyCollection`, so pagination and sorting stay correct.
- **Order**: hand-picked ones may use their own order (`manual`); every
  collection may sort newest, price, name or best selling (units on counted
  orders).
- **Writes**: only through `CollectionService` (audit `collection.*`, outbox
  event `collection.created/updated`).
- Storefront: `/collections/{slug}` page (listing with the collection's order and
  the usual filters), `GET /api/v1/storefront/collections` and
  `/collections/{slug}`; products of one: `/storefront/products?collection=slug`.
  Tags: `/tags/{slug}` (not indexed) and `?tag=slug`.
- Collection names and descriptions are translatable (B38
  `TranslationService`, type `collection`).

## 3. Featured, home section and related products

- `featured_products` home section gained `source`: `newest` (unchanged default),
  `featured`, or `collection` (+ `collection` slug; a collection that is not live
  leaves the section empty). Validated in `ThemeConfigValidator`.
- Product page: `related` (related + alternative relations; when none, others of
  its category that are not already shown as cross-/up-sell), `cross_sell`
  ("Goes well with"), `up_sell` ("You might prefer"). Only browsable, active
  products are shown.

## 4. Duplication (Module 06 §60)

`POST /api/v1/products/{id}/duplicate` — `ProductDuplicator`: new id, safe new
slug, name "… (copy)", **draft and hidden**, SKU and barcodes empty, categories,
tags, hand-picked collections and variants copied, images copied as new files
(removed again if the copy fails). Not copied: stock, reviews, orders, relations.
A draft counts against `max_products`, so the limit is checked first and usage
recorded after.

## 5. Bulk changes (Module 06 §46–47)

`POST /api/v1/products/bulk` `{action, products[≤500], params}` —
`BulkProductService`. Actions: publish, unpublish, archive, delete, set
visibility, set featured, set / add category, add to / remove from a hand-picked
collection, add / remove tags, set price, change price by %, put on sale (% off),
end sale.

Safety, per §47:

| Rule | How |
|---|---|
| Affected count | The bar shows how many are chosen; the answer gives `affected` and each `skipped` product with its reason. |
| Confirm destructive actions | Delete asks first (admin). |
| Package limits | Leaving `archived` checks `max_products` per product; usage is recorded / released like a single change. |
| Permissions | Checked **per product** through the product policy (`update`, or `delete`); collection actions need `collections.manage`. |
| Audit | One `product.bulk_{action}` entry with the ids and parameters. |
| Partial failures | Each product in its own transaction; one failure never undoes the others. |
| Cross-tenant | Products are looked up through the tenant scope; another store's id is reported "not found". |
| Background jobs | Not used: at most 500 products per request run synchronously. A queue is needed only for larger jobs (import, B40). |

Publishing requires a price (or variants); a sale price that would no longer be
below a new price is removed.

## 6. Promotions aimed at a collection (Module 14 §9)

`PromotionTargetScope::Collection`. A cart line matches when its product is in
one of the promotion's **live** collections now (manual membership or rules).
Only collections that some promotion targets are evaluated, so a cart without
such a promotion costs no extra queries. Promotion targets of every scope are now
checked to be items of the store (before B39 any integer id was stored).

## 7. Admin

- Catalog → **Collections**: list (shown / draft / hidden / outside its
  schedule), editor with the rule builder and schedule, product picker with order,
  Translate.
- **Products** list: choose products (or a whole page), bulk bar with the result
  report, Duplicate per row.
- **Product** page: Merchandising card (featured, tags, hand-picked collections),
  "Shown with this product" card (four ordered pickers), Duplicate.
- **Promotions**: "Chosen collections" target.
- **Theme** editor: products source of the featured products section.

## 8. Not built (still open in G15 / these sections)

- Badges configuration (§36) — the storefront keeps its existing sale / stock
  labels.
- Featured influencing search ranking or category sections (§37) — only the home
  section uses it.
- Bulk "update stock reference" and "export" (§46) — export comes with B40.
- Import/export (§48–51) — B40. Attribute sets and faceted filters — B41.
- A scheduled collection appears or disappears within the storefront cache
  lifetime (`STOREFRONT_CACHE_TTL`, 10 minutes by default), not at the exact
  minute; promotions use the exact time.
