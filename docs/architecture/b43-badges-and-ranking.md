# B43 — Product badges and featured ranking

Module 06 §36 (badges), §37 (featured), §93 (catalog merchandising: featured,
sort priority, manual ordering, collection/category priority), §94 (manual
sorting); Module 05 §19 (sorting, configurable default), §12/§64 (card badges);
Module 07 §17 (category product ordering); Module 17 §8, §26 (badge colours,
card badges). Specs read before building.

## 1. Badges (§36)

**Separated from the product's core data**, as §36 asks: nothing about
automatic badges is stored on the product. `ProductBadges` works them out for a
whole list of products with a fixed number of queries (store settings, at most
one bestseller query, one stock query, one store-badge query), whatever the
list's length.

| Badge | Shown when | Setting | Priority | Tone |
|---|---|---|---|---|
| Sold out | nothing can be bought | `badges.out_of_stock` (on) | 100 | neutral |
| Sale / *N% off* | on sale; the percent only for one price (no variants) | `badges.sale` (on), `badges.sale_percent` (on) | 90 | danger |
| Only a few left | stock in the default warehouse ≤ threshold, tracked products only | `badges.low_stock` (**off**), `badges.low_stock_threshold` (5) | 80 | warning |
| New | published within N days | `badges.new` (on), `badges.new_days` (14) | 70 | accent |
| Bestseller | among the top N products by units sold in the last D days (orders not draft/cancelled/failed) | `badges.bestseller` (on), `_count` (10), `_days` (30) | 60 | success |
| Featured | marked featured | `badges.featured` (on) | 40 | accent |
| Store's own (`badges` table) | put on the product | label, tone, priority 1–200, active | own | own |

- Ordered by priority (higher first; the type and label break ties — the same
  order every time) and cut to `badges.max_per_product` (2).
- "Only a few left" is off by default: it tells shoppers stock is low — the
  merchant decides.
- Automatic labels are **not** sent: the storefront words them in the
  visitor's language (`Sale`, `25% off`, `نیا`, `25٪ رعایت`…). The store's own
  labels are translatable (`TranslationService` type `badge`).
- Colours are the theme's tokens (`sf-accent`, `sf-success`, `sf-warning`,
  `sf-error`, dark neutral), so every theme keeps its look (Module 17 §8).
- Cards (`cards()`), the product page and every product list carry `badges`.
  "New" and "Bestseller" follow time; they refresh with the storefront cache
  (`STOREFRONT_CACHE_TTL`, 10 minutes).

## 2. Featured ranking (§37, §93; Module 05 §19; Module 07 §17)

`products.sort_priority` (−1000…1000) is the merchandising boost (§93 "sort
priority").

**Featured order** (`sort=featured`): `is_featured DESC, sort_priority DESC,
COALESCE(published_at, created_at) DESC, id DESC` — deterministic (§37: "final
ranking rules must remain deterministic and configurable"): the same products
always come in the same order.

**Which order a page uses** (`StorefrontCatalog::effectiveSort`):

1. the shopper's choice (`?sort=`);
2. a collection's own order;
3. on a search: relevance;
4. the category's `default_sort` (Module 07 §17);
5. the store's `catalog.default_sort` (Module 05 §19; default `newest`, so
   existing stores see no change until they choose);
6. newest.

The listing answers with `sort` (the order actually used), so the sort menu
shows the page's real default.

**Search** (§37 "search ranking"): name matches first (`name LIKE 'q%'`); then,
when `catalog.featured_in_search` is on (default), featured and higher-priority
products among equal matches; then newest. A featured product never jumps over
a better match.

**Elsewhere** (§37): the home "featured products" section and "You may also
like" (category fallback) use the featured order; collections can choose
"featured" as their order; rule-based collections already had a `featured`
condition (B39).

Sort menu: Featured, Newest, Best selling, Price, Name; "Most relevant" on
searches; "Recommended" (the collection's own order) on collection pages.

## 3. Admin

- **Catalog → Badges**: the store's badges (label, colour, priority, shown),
  preview, translate; the automatic badges and their priorities explained, with
  a link to Settings.
- **Product → Organisation → Merchandising**: sort priority and the store's
  badges.
- **Categories → Edit**: "Products on its page" (default order).
- **Settings**: default product order, search lift, each automatic badge and its
  numbers, badges per product.
- **Bulk bar**: add / remove a badge, set sort priority.
- **CSV**: `sort_priority` and `badges` (labels; must exist) columns, in and out.
- Duplicates keep badges and sort priority.
- Demo store (`demo:store`, `--refresh-look`): three featured products with
  priorities, "Handmade" and "Eid special" badges with Urdu labels, "featured"
  as the default order.

## 4. Permissions

Badges are merchandising: defined with `collections.manage` (Administrator,
Manager, Content & Marketing, Owner), seen with `products.view`; putting a
badge on a product is a product change (`products.update`). Settings need
`settings.manage` as before.

## 5. Not built / decisions

- Category-specific **manual** product order (Module 07 §17 "manual ordering",
  §94): hand-made order exists for collections (B39); per-category positions
  would need a scalable ordering model (§94) — `sort_priority` covers boosting
  without rewrites. Not built.
- Ratings-based badges and sort (§19 "Rating", "Popularity"): no reviews module
  yet.
- Badge schedules and badge animation (Module 18 §… "badge animation"): not
  built.
- Package limits on badges: none in the specs; not invented.
