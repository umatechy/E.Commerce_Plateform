# B41 — Attributes, attribute sets, specifications and category filters

Gap **G15**, part 3. Specs read before building: Module 07 §18–20 (category
filters, category attributes, templates), §27–49 (attributes, groups, types,
values, normalization, localization, variant options, assignment, sets,
category assignment, display modes, filter engine, faceting, URL state),
§51–52 (entitlements, limits), §75–79 (deactivation, filter visibility and
order).

## 1. Data (migration `2028_12_01_000001`)

| Table / column | Purpose |
|---|---|
| `attributes.group`, `unit`, `is_active`, `sort_order` | §28 groups, §33 unit, §75 deactivation, order |
| type `color` (new) | §35: a single choice whose values carry `#RRGGBB` |
| `attribute_values.slug`, `color_code`, `is_active` | §36 value id/slug/colour/status; slug is the filter address |
| `attribute_sets`, `attribute_set_items` | §44 reusable lists |
| `category_attributes` | §18–19, §45: per category, ordered, `is_required`, `is_filter` |
| `product_attribute_values` | §41: typed — chosen value (one row per value for multi-select), number (18,4), yes/no, text (500) |

Existing values got slugs in the migration.

## 2. Rules

- **Values** are edited as the full ordered list (`AttributeManager`). A value
  no longer listed is deleted only if no product uses it; otherwise it becomes
  inactive (§76) and is no longer offered or filterable. Values are unique per
  attribute by their normalized text (§37). Colour codes are validated.
- The **type** cannot change once products use the attribute.
- **Category attributes** (`CategoryAttributes`): up to 40, a text attribute
  cannot be a filter, ids are checked in the store. A category without its own
  list **follows its nearest parent's**. Applying a set adds its attributes
  at the end.
- **Specifications** (`ProductSpecifications`): typed validation per attribute;
  inactive values cannot be chosen; the **required** attributes of the
  product's main category (or the list it follows) must be filled. Saving
  replaces the product's specifications. Duplicating a product copies them.
- **Attributes vs variant options** (§40): specifications describe a product;
  variants keep their own option values. Nothing turns an attribute into a
  variant automatically.

## 3. Storefront

- Product page: `specifications` (name, value with unit, colour swatches), in
  the attributes' order; inactive attributes and values are left out.
- Category page filters (§47–49): `attr[key]=slug,slug` for choices,
  `attr[key]=min-max` for numbers (either side may be empty), `attr[key]=1`
  for yes/no. **Only the category's filter attributes are read; unknown keys
  and values are dropped** — never a column name, never another store's data.
  Filtered pages are `noindex, follow` (as other refined listings).
- Facets (§48): for each filter attribute, the count of matching products per
  value with **all other filters applied but its own**; number ranges report
  min/max; values without products are not offered (§77).
- Brand, price and stock filters stay as before (§78 order: those first, then
  the category's attributes in its order).
- Listings and facets are cached per store, language and filter set (the
  storefront cache); attribute, category-attribute and product changes clear it.

## 4. Found and fixed

`StorefrontCatalog` memoizes categories and the default warehouse. It was held
in a property of services that live inside a controller Laravel caches on its
route, so in a long-running process one request's memo could reach the next —
including another store's. It is now **request-scoped** and resolved per call
(`catalog()`), like `StorefrontLocale` in B38.

## 5. Admin

- **Attributes**: edit in place (name, group, unit, type, active), values in
  order with colour codes and "offered"; attribute sets.
- **Categories**: "Attributes & filters" — add attributes or a set, mark
  required / filter, reorder; shows the parent list a category follows.
- **Product**: "Specifications" card — the category's suggestions first,
  required ones marked, any other attribute can be added.

## 6. Not built / decisions

- Package limits and "advanced filtering" by package (§51–52): the specs give
  no values ("exact limits must be finalized with Module 04"). Not invented;
  filters are available to every package.
- Attribute and value **translations** (§38) — the identifiers are stable, but
  names and values show in the original language on Urdu pages.
- Attributes in the CSV import/export (§48), category templates (§20), date and
  currency types (§29 future), unit conversion (§34), display modes per
  attribute (§46: list only), filters on search and all-products pages.
- Facets run one count query per filter attribute; fine for the current catalog
  sizes, a search engine would be needed at large scale (§50).
