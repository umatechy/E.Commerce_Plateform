# B39 security review — collections, tags, relations, duplication, bulk changes

| Area | Risk | Control | Test |
|---|---|---|---|
| Tenant isolation | Store B reads or changes store A's collections, tags or relations | `BelongsToTenant` on Collection, Tag, ProductRelation; route binding through the tenant scope (404) | `test_collections_need_their_permission_and_stay_in_their_store` |
| Ids in rules, products, relations | Another store's category / product id in a rule, a collection or a relation | Every id is looked up through the tenant scope before it is stored (`CollectionRules::ids`, `CollectionService::setProducts`, relations, `collection_ids`) | rule-based and relations tests |
| Arbitrary SQL / columns | A rule naming a database column | Fixed list of fields, each with its own typed value; unknown keys refused | `cost_price_minor` rule refused |
| Promotion targets | Any integer stored as a target (pre-existing) | B39 checks targets of every scope belong to the store | `test_a_promotion_can_aim_at_a_collection` |
| Permissions | Viewer changes collections; editor changes collections through the product form | `collections.manage` (policy) for collection writes and `collection_ids`; bulk actions checked per product with the product policy | collections and bulk tests |
| Package limit bypass | Duplicate or bulk unarchive past `max_products` | Duplicate: `assertCanUse` then `recordUsage`; bulk status changes check and record per product | duplicate and bulk tests |
| Hidden content leak | Scheduled / draft collection or draft product shown | Only live collections are shown; only active, browsable products are listed | manual collection test |
| Abuse | Large or repeated bulk calls | 500 products per call, `throttle:20,1` on bulk, `throttle:30,1` on duplicate | — |
| Audit | Untraceable mass changes | `product.bulk_*`, `product.duplicated`, `product.relations_updated`, `collection.*` | bulk and duplicate tests |
| Stored text | Script in names / tags | Plain text only; React escapes; tag length 60, collection name 120 | — |
| Media | Copy points to the same file | Duplicate copies image files; failure removes the copies | — |

No new secrets, credentials or provider calls. No change to authentication, MFA or
step-up.

Residual: bulk runs synchronously (≤ 500 products); scheduled collections follow
the storefront cache lifetime (≤ 10 minutes) — documented in
`docs/architecture/b39-collections-merchandising.md` §8.
