# B47 security review — billing completion

| Area | Risk | Control | Test |
|---|---|---|---|
| Who changes a plan | Staff without authority upgrading (a charge) or downgrading | `billing.manage` (Owner by default); preview needs `billing.view`; throttled | staff 403 |
| Free service | Getting a period for free through a plan change | issued invoices are never voided or changed by a plan change; unused value is credited only for a paid period; overdue invoices block upgrades | issued-renewal test |
| Self-declared payment | A store marking its own invoice paid | a notice changes nothing; only platform staff confirm (step-up), through the single payment path, once per notice | notice test (invoice stays open; second approve 409) |
| Refund fraud | One person issuing a large refund or credit | threshold approval by a **different** platform staff member; every step audited with requester and approver; step-up on create, approve, reject | same-person 403 |
| Over-crediting | Credits or refunds above what was paid; double credits | limits checked under the subscription and invoice locks; pending notes count; idempotency key per request | amount_exceeds_available |
| Tenant isolation | Another store's invoice, credit note or PDF | all billing models are tenant-scoped; store routes answer 404 for another store's documents; Super Admin routes are platform-only | stranger 404; owner 403 on super-admin |
| Immutability | Rewriting issued documents | credit notes immutable after issue; corrections are new records; account credit is an append-only ledger; PDFs render stored data | — |
| PDF rendering | Remote fetches, script or PHP execution in templates | dompdf with remote loading and PHP disabled; Blade escapes all values; `nosniff`, `no-store`, attachment disposition; throttled | PDF assertions |
| Money maths | Float drift | integers only; half-up; proration by seconds | upgrade test (hand-checked figures) |
| Concurrency | Double application of credit or a double plan change | subscription row lock, the same order as the billing run | — |

New dependency: `dompdf/dompdf` (PDF rendering, runs locally). No secrets or
external services added.
