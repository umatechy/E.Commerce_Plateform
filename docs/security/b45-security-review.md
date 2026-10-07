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
| Input | Unknown template keys, odd options | route pattern `[a-z0-9_]{1,40}`, 404 for unknown keys, boolean options validated; throttled 10 per minute | preview / 404 assertions |
| Audit | Untraceable changes | history row, audit `catalog.starter_template_applied`, outbox event; per-category attribute changes keep their own audit entries | apply test |


## Follow-up (2026-10-06): Urdu, platform-managed templates, store to template

| Area | Risk | Control | Test |
|---|---|---|---|
| Who manages templates | A store user changing platform templates | Super Admin routes only (`can:super-admin.platform`, privileged MFA); every change needs **step-up** and is audited (`starter_template.updated`, `.deleted`, `.saved_from_store`) | owner → 403; StepUpTest route list |
| Data leaking between stores (cloning) | Copying one store's customers, orders, products or secrets into another | the capture reads only categories, attributes, values, category-attribute flags, brand names, theme key, default order and Urdu text, in the source store's context; nothing else is read | saved template contains no product, archived category or inactive attribute |
| Names of one store shown to others | A saved template offered to every store by accident | saved templates start **staff only**; offering is a separate, audited step | not offered → other owner 404 |
| Template switched off | Still usable by URL | owner preview/apply and staff creation check `usable()` (on, and offered for owners); 404 / 422 otherwise | switch-off test |
| Built-in content changed in the admin | Unreviewed changes to platform templates | built-in rows accept only on/off and offered (`prohibited` for name, summary, category) | 422 assertion |
| Translations | Writing over the owner's own translations | Urdu is written only for items the template creates now | own category has no new translation |
| Size | A huge store saved as a template | limits checked by the same rules as built-in templates (60 top-level categories, 40 children each, 60 attributes, 200 values, 100 brands) | rule test |

No new secrets, providers or public endpoints.
