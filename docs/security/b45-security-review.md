# B45 security review — starter templates

| Area | Risk | Control | Test |
|---|---|---|---|
| Tenant isolation | A template writing into another store, or a same-named category of another store taken as this store's | every read and write goes through tenant-scoped models in the current store context; provisioning uses `TenantContext::asStore()`; the history row carries the store id | "only adds" test (another store's "Men" untouched and not matched) |
| Privilege | Applying a template to do more than the person may | apply needs categories **and** attributes management; brands need `brands.manage`; the theme needs `theme.publish`; a refused request writes nothing | permissions test |
| Data loss | Overwriting or deleting the owner's own structure | add-only: existing categories, attributes (and their type), flags, brands and the chosen default order are kept; nothing is deleted or re-typed (Module 07 §103) | "only adds" test |
| Duplicates under concurrency | Two clicks creating categories twice | one transaction with the store row locked; matching by name under the same parent | re-apply adds nothing |
| Misleading storefront | A new store showing things that look real | templates contain no products, prices, reviews or policies; brands only on request | template shape test |
| Package limits | A template giving a theme the package does not include | the theme is chosen by the package's entitlements; publishing re-checks them; a refused theme leaves the theme unchanged | sign-up test (Basic stays on Classic) |
| Live store changes | The look of a live store changing without the owner | on a live store the theme goes to the draft only | apply test (draft boutique, published default) |
| Input | Unknown template keys, odd options | route pattern `[a-z_]{1,32}`, 404 for unknown keys, boolean options validated; throttled 10 per minute | preview / 404 assertions |
| Audit | Untraceable changes | history row, audit `catalog.starter_template_applied`, outbox event; per-category attribute changes keep their own audit entries | apply test |

No new secrets, providers, public endpoints or authentication changes.
