# B46 security review — tax engine

| Area | Risk | Control | Test |
|---|---|---|---|
| Price manipulation | A client sending its own tax or totals | tax is computed in `OrderService` from the store's rules; checkout and the summary take only an address and a delivery option (Module 05 §75) | checkout tests; summary equals the order |
| Who sets tax | Staff without authority changing rates or turning tax on | `tax.manage` (Owner, Administrator) for every change; managers see the page (settings.view) but cannot change it | manager 403 |
| Settings bypass | Changing `tax.*` through the generic settings API, skipping `tax.manage` and the rate check | generic API neither lists nor changes `tax.*` (also rollback) — 403 `managed_on_tax_page` | settings bypass assertion |
| Tenant isolation | Another store's tax class used on a rate or product; another store's rates applied | all tax models are tenant-scoped; class ids checked in the store's scope (rates, products, preview) | foreign class → 422 |
| Self-exemption | A customer marking themselves exempt | `tax_exempt` is not mass-assignable; only the exemption endpoint (tax.manage + customers.manage) sets it | manager 403; exemption test |
| History | Old orders changing when rates change | full tax snapshot on the order; refunds and documents read the order | rate change after order |
| Correctness | Rounding drift, floating point | integers only, half-up, exact splits (`MoneyAllocation`); policy version on the order | unit tests |
| Double charge | Inclusive prices refunding tax twice | refunds add line tax only for exclusive prices | inclusive refund test |
| Audit | Untraceable tax changes | audit entries with before/after for settings, classes, rates and exemptions | audit assertions |
| Abuse | Hammering the summary or preview | throttled (60/min) | — |

No secrets or providers added. No rate is shipped with the platform.

## Follow-up (2026-10-07): fonts, reviews and units sold, button motion

| Area | Risk | Control | Test |
|---|---|---|---|
| Fake ratings | Ratings or reviews that were never given | only buyers (an order of theirs with the product, not draft/cancelled/failed) may review; one per product; shown only after the store approves | buyer / stranger / guest assertions |
| Review spam | Many reviews from one account | one review per customer and product (unique key); 10 per 10 minutes per client | — |
| Blocked customers | Reviewing after being blocked | refused before the review rules (B32 standing) | blocked → 403 |
| Personal data | Full names or emails on the storefront | the shown name is first name + initial; the public answer has no email or customer id; export lists reviews; erasure deletes them and recounts | erasure assertion |
| Moderation rights | Anyone changing reviews | `reviews.manage` (Owner, Administrator, Manager, Content & Marketing) and the package feature; reviews are tenant-scoped (another store's review → 404) | staff 403, other store 404 |
| Package | Basic stores showing ratings or units sold | both features checked server-side (`reviews.product`, `products.units_sold`); the storefront endpoints answer 404 | Basic test |
| Stored XSS | Script in a review or reply | stored as text, rendered as text by React (no HTML) | — |
| Font privacy | Third-party font requests | fonts stay self-hosted; serif packages removed | — |
