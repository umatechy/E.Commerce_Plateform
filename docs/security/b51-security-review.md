# B51 security review — store lifecycle and closure

| Area | Risk | Control | Test |
|---|---|---|---|
| Closing a store | A team member or an attacker with a session closes the store | Owner role only (not a grantable permission); store name typed in full; step-up (password + two-step code); throttled; audited; owner emailed | non-owner 403; wrong name 422 |
| Accidental permanent deletion | Data lost by one click | Only for archived stores past retention; no legal hold; no open invoice; reason (≥10 chars) and store address typed; step-up; cooling period (cancellable); verified platform backup first; final count of every purged table | deletion tests (refusals, cancel, failed backup, completion) |
| Partial deletion | A failure leaves a half-deleted store marked deleted | Store marked deleted only when every purged table counts zero; failures recorded, retry continues; FK order computed from the database (no constraints disabled) | failed-backup and completion tests; real run on MySQL |
| Wrong data deleted | Another store's rows or retained records removed | explicit `where store_id = ?` on every delete; retained tables never written; classification test fails for unknown tables; purger refuses with unclassified tables | other store untouched; retained rows present |
| Financial / legal records | Billing or audit evidence destroyed | RETAIN list: invoices, payments to Umar Techy, credit notes, subscriptions, audit trail, lifecycle history, deletion record; store row soft-deleted for references | retained assertions |
| Personal data after closure | Customer data kept forever | purged after retention + approval; marketing stops at closure; backups expire per policy | purge assertions |
| Read-only bypass | A closed/suspended store keeps changing data or selling | middleware on all staff routes (423); API keys refused; storefront closed by the live gate; marketing suppressed | write 423, API key 423, storefront 503 |
| Suspension abuse | Unexplained or silent suspension | reason from a fixed list + note required; history + audit; owner emailed; reactivation checks the subscription | suspension test |
| Exports | Data leak through the export | owner only, tenant-scoped by explicit store id, audited, throttled, no-store cache headers, formula-safe cells; not available once archived | export tests |
| Platform rights | Any platform staff member can suspend or delete | existing platform design (all staff share platform rights, noted in B50); step-up and audit on every action | — |

No new dependency. No secret added. The deletion backup is a full platform dump in the existing encrypted backup storage.
