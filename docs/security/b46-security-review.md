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
