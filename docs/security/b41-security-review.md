# B41 security review — attributes, specifications and filters

| Area | Risk | Control | Test |
|---|---|---|---|
| Tenant isolation | Another store's attribute in a category or specification; editing another store's attribute | Attributes, sets, category attributes and specifications are tenant-scoped; ids are looked up through the scope; binding answers 404 | `test_attribute_sets_apply_to_a_category_and_stay_in_their_store` |
| Filter injection (§49) | Address names a column or arbitrary value | Only the category's filter attributes are read, values by slug from their own list, numbers by a strict pattern; everything else dropped | `test_category_filters_…` (unknown keys ignored) |
| Cross-request memo | Category list of one request/store reused by the next in a long-running worker | `StorefrontCatalog` request-scoped and resolved per call | category filter test (child category) |
| Permissions | Product editors change attributes or category lists | `attributes.manage` for attributes and sets; category policy `manage` for its list; product `update` for specifications | sets test |
| Data integrity | Deleting a value products use; changing a used attribute's type | Used values become inactive; type change refused | `test_attribute_values_…` |
| Input limits | Huge lists | 200 values, 40 per category, 60 specifications, 30 multi-select choices, 10 filters per address, lengths capped | — |
| Stored text | Script in values | Text only; React escapes; colour codes must match `#RRGGBB` before reaching a style attribute | values test |
| SEO / crawl traps | Endless filter combinations indexed | Filtered listings `noindex, follow` | category filter test |
| Audit | Untraceable taxonomy changes | `attribute.updated`, `attribute_set.saved`, `category.attributes_updated`, `product.specifications_updated` | — |

No new secrets, providers or outgoing calls; authentication, MFA and step-up
unchanged.
