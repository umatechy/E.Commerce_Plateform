# Phase B23 — Security Review (Module 29)

All items below were verified by executed tests on MySQL 8, where a test
is named (`tests/Feature/Billing/`).

| # | Threat | Control | Status |
|---|---|---|---|
| 1 | A store reading another store's invoices | `Invoice` is tenant-scoped (`BelongsToTenant`); the store is the resolved tenant, never input; route key is the ULID `public_id` | Tested (`test_the_owner_lists_and_reads_only_their_own_invoices`: foreign invoice → 404) |
| 2 | Internal ids leaking | Resources expose `public_id` and invoice numbers only; no `store_id`/`subscription_id` | Tested (`assertJsonMissingPath('data.data.0.store_id')`) |
| 3 | Staff changing billing without authority | `billing.view` / `billing.manage` (Owner by default, not the seeded Manager); a view-only user cannot cancel | Tested |
| 4 | Customer or store staff reaching platform billing | Staff routes answer customer tokens with 401; Super Admin routes sit behind `can:super-admin.platform` + `super_admin.platform` (403 for store owners) | Tested |
| 5 | Recording the same money twice (retry, double click) | Required `idempotency_key`, unique in the database, checked under the subscription row lock; a key reused for another invoice is refused | Tested |
| 6 | Over-payment / paying a closed invoice | Amount capped at the balance due (422); only `open` invoices take payments (409) | Tested |
| 7 | Concurrent billing run and payment corrupting state | Every path locks the subscription row, then its invoices (fixed order, no deadlock); one invoice per period (unique index) | Reviewed |
| 8 | Invoice number gaps or reuse | Counter row locked and advanced in the issuing transaction; a rollback also rolls back the number | Reviewed |
| 9 | Suspending a store by mistake | Unpriced packages are never billed or dunned; late-issued invoices are due when issued; past-due and grace keep access; a manual suspension is never lifted by a payment | Tested |
| 10 | Destroying data on non-payment | Suspension and expiry change access only (Module 04 §20/§37); no business data is touched; expired stores keep their data and can be reactivated | Reviewed |
| 11 | Silent money changes | Every payment, void, due-date extension and price change is written to the tamper-evident audit trail (Module 32) with the acting Super Admin | Tested |
| 12 | Float rounding in money | Integer minor units throughout; tax is `intdiv(amount × bps + 5000, 10000)` | Tested (2900 at 17% → 493) |
| 13 | Mass assignment | Validated, whitelisted input only; status, totals and ownership are never taken from a request | Reviewed |
| 14 | Filter injection | Enum-validated `status`; `store` is a 26-character public id; `per_page` ≤ 100 | Tested |
| 15 | Notification replay | Owner mails use per-event idempotency keys (per stage and due date for overdue); redelivery sends nothing twice | Tested |

## Residual Risks

- Payments are asserted by a Super Admin, not verified with a bank. The
  audit trail records who recorded each one.
- There is no maker-checker (second approval) on voids or large
  payments.
- The revenue summary aggregates at request time; it is fine at the
  current scale, but a materialized daily figure is the next step for
  thousands of stores.
