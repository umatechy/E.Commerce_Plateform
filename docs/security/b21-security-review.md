# Phase B21 — Security Review

Unlike B0–B20, the items below were verified by executed tests against
MySQL 8 where a test is named.

## Security Defects Found by the First Real Run (fixed)

| Area | Defect | Impact | Fix / test |
|---|---|---|---|
| SEO redirects | External-URL guard ran after normalization; `https://…`, `//…`, `/\…` and `javascript:` destinations were stored as "internal" redirects | Open redirect from any store's domain | Guard runs on the raw input and rejects schemes, `//`, backslashes and control characters — `RuntimeRegressionTest::test_redirects_never_point_off_site` |
| Payments | Refundable balance checked outside the row lock | Two concurrent partial refunds could together exceed the captured amount | Balance re-read under `lockForUpdate()` inside the refund transaction |
| Settings | Store endpoint answered a platform-scope key with a 422 naming it | Disclosed which platform settings exist | Answered exactly like an unknown key (404) — `StoreSettingAdminTest` |
| Themes | Draft permission checked after request validation | Unauthorized users learned the payload rules (422 before 403) | Checked in `UpdateThemeDraftRequest::authorize()` — `ThemeAdminTest` |
| Tenancy | Tenant resolution ran after route-model binding | Bindings of tenant models failed (500) instead of resolving under the caller's tenant | Middleware priority; ADR-001's "guessed id is 404" now holds — `DomainAdminTest::test_store_a_cannot_set_store_bs_domain_as_primary` |
| Developer API | No route-model binding on `/api/dev/v1` | `show()` returned an empty model (not a leak, but tenant-scoped lookups never ran); now scope check precedes binding | `RuntimeRegressionTest::test_developer_api_show_returns_the_stores_actual_product` |
| Tests | A test's resolved tenant context leaked into simulated requests | Isolation tests could pass for the wrong reason | Fresh request-scoped state per test request |

## Module 24 Checklist

| # | Item | Finding | Status |
|---|---|---|---|
| 1 | Cross-tenant read in health checks | `evaluate()` refuses any store other than the resolved tenant; unscoped tables filtered by `store_id` explicitly | Tested (`test_evaluating_a_store_outside_its_own_tenant_context_is_refused`, `test_another_stores_failures_never_affect_this_stores_health`) |
| 2 | Cross-tenant history | `/store/health/history` is scoped by `BelongsToTenant`; no store parameter exists | Tested (`test_history_only_ever_lists_the_callers_own_store`) |
| 3 | Authorization | `store_health.view` or Owner; staff without it get 403; customer tokens 401 | Tested |
| 4 | Super Admin reach | Platform routes behind `can:super-admin.platform` + middleware; per-store live view behind the audited impersonation group | Tested (non-staff 403 on all four routes) |
| 5 | Internal id exposure | Snapshot resources expose the store's `public_id`, never `id`/`store_id` (ADR-003) | Tested |
| 6 | Secret leakage | Checks report counts, statuses and hostnames only — no webhook secrets, tokens, payloads or recipient addresses | Reviewed |
| 7 | Query cost / DoS | Store health is a bounded set of indexed counts; the platform overview reads snapshots (one indexed subquery), never recomputes all stores per request; `days` capped at 90 | Reviewed |
| 8 | Filter injection | `status` validated against the enum; the API-usage regex is a constant | Tested (`status=bogus` → 422) |
| 9 | Snapshot job failure | One failing store is logged and skipped; others still recorded | Reviewed |

## Residual Risks

- Staff SPA login on a non-stateful request now returns 200 without
  creating a session (previously a 500). Clients outside
  `SANCTUM_STATEFUL_DOMAINS` must use tokens; a clearer refusal for that
  case is worth adding.
- Thresholds are defaults, not measured values.
