# B53 security review — API quality

| Area | Risk | Control | Test |
|---|---|---|---|
| Error answers | Stack traces, SQL, file paths or secrets leak in errors | NormalizeApiErrors drops exception/file/line/trace outside a local debug run; every 5xx gets a generic message; unhandled `/api` exceptions answered as JSON | server-error test |
| Webhook data exposure | Third parties subscribe to internal events (backups, settings, billing, security) and receive internal payloads | WebhookEventCatalog allowlist at subscription and at delivery; only documented fields sent; old subscriptions to other events stop receiving them | webhook catalog test |
| Duplicate writes | A retried request creates a second order, refund or payment | Idempotency-Key replays the first answer; key reuse with another body refused; in-progress requests refused; keys scoped to the caller; 5xx not kept | idempotency test |
| Idempotency store | Responses kept forever, or replayed to another caller | Keys per actor (user/customer/API key), 24 h, pruned daily; store rows purged on store deletion | — |
| Pagination | Records skipped or doubled across pages (integration data loss) | Fixed ordering by id on every developer API list | contract test |
| Documentation drift | Integrators build on documented behaviour that is no longer true | OpenAPI built from routes; undocumented route fails the build; CI checks the committed file and lints it; contract test compares real answers with the schema | OpenAPI and contract tests |
| Public spec | The description reveals something sensitive | Spec only describes endpoints, scopes and fields of the read-only API; throttled; no data | — |
| E2E credentials | Test passwords or keys committed or reused | Generated fresh on every `e2e:prepare`, written to storage/app (git-ignored), command refuses in production | — |

No secret added. One new CI tool run on demand (Redocly CLI, pinned 2.63.0); no new runtime dependency.
