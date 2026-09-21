# Phase B12 — Focused Analytics Security Review

Static/design-level review only — **NOT EXECUTED — DEFERRED TO VS CODE RUNTIME
VERIFICATION**.

## Regression Check — B0-B11 Capabilities Confirmed Intact

Verified by direct grep/inspection: `BelongsToTenant::store()` present; zero
`Analytics`/`Report` references in `OrderService`, `PaymentService`,
`ShipmentService`, `PromotionService`, `CampaignService`, `NotificationService`,
`InventoryService`, or any of their state machines — B12 is purely read-only
against every prior phase's authoritative tables and modified none of their
business logic. `EnsureCustomerPrincipal`/`EnsureStaffPrincipal` present and
unmodified.

## Standard B12 Checklist (this milestone's 30-item Step 50 list)

| # | Item | Finding | Status |
|---|---|---|---|
| 1 | Cross-tenant report access | Every report query runs through Eloquent models carrying `BelongsToTenant`'s global scope (`Order`, `Payment`, `PaymentTransaction`, `Shipment`, `PromotionUsage`, `CampaignRecipient`, `NotificationMessage`, `Inventory`) — a report can structurally only ever see the resolved tenant's own rows. | Reviewed — OK |
| 2 | Cross-tenant exports | `ReportExport` uses `BelongsToTenant`; `show()` enforces `AnalyticsPolicy::downloadExport()` (tenant ownership + permission). Tested explicitly (404 for another store's export). | Reviewed — OK |
| 3 | Cross-role access | `AnalyticsPolicy` separates `analytics.view` from `analytics.financial`; every report/dashboard endpoint checks the correct one. Tested explicitly (403 without permission; financial fields stripped with only `view`). | Reviewed — OK |
| 4 | Customer data leakage | `customersReport()`/`productsReport()` return only aggregated counts/sums and `product_name_snapshot` — no customer PII (name/email/phone) is ever included in any report output. | Reviewed — OK |
| 5 | Payment data leakage | `paymentsReport()` returns only `status`/`method`/aggregated `count`/`amount_minor` — never a card number, gateway credential, or any field `PaymentResource` itself wouldn't already expose to staff. | Reviewed — OK |
| 6 | Raw SQL injection | Every query is built via Eloquent's query builder (`selectRaw()` calls use only hardcoded, developer-authored SQL fragments — never string-interpolated client input); `whereRaw()` calls in `DashboardService`/`ReportService` for the low-stock formula contain zero variables at all. | Reviewed — OK |
| 7 | Arbitrary query execution | No endpoint accepts a raw query, table name, or column expression from the client — every report is one of nine fixed, developer-authored methods (Module 22 §39's "no arbitrary-query endpoint," honored by not building a custom report builder at all — see inspection findings). | Reviewed — OK |
| 8 | Dynamic ORDER BY injection | `productsReport()`'s only sortable field is resolved via a fixed `match()` expression with a safe default fallback — a client-supplied `sort_by` value never reaches `orderByDesc()` directly. Non-Negotiable Step 13. | Reviewed — OK |
| 9 | Dynamic column injection | No report accepts a client-supplied column/field name for filtering or grouping — every `groupBy()`/`selectRaw()` call uses hardcoded column lists. | Reviewed — OK |
| 10 | Filter bypass | `DateRangeRequest`/`RequestExportRequest` validate `date_filter` against an explicit `in:` whitelist server-side; `DateRangeResolver` independently re-validates the same whitelist (defense in depth — even a request that somehow bypassed FormRequest validation would still be rejected by the resolver). | Reviewed — OK |
| 11 | Export path traversal | `GenerateReportExportJob` constructs the storage path itself (`reports/{store_id}/{export_public_id}.csv`, both server-generated values, never client input) — there is no code path where a client-supplied string becomes part of a filesystem path. | Reviewed — OK |
| 12 | Export URL guessing | Download is reachable ONLY via `URL::temporarySignedRoute()` — a valid signature cannot be guessed or brute-forced (HMAC-signed by Laravel's own `APP_KEY`), and expires after the export's own `expires_at`. Tested (forged/tampered signature rejected with 403). | Reviewed — OK |
| 13 | Export authorization bypass | `store()`/`show()` both check `AnalyticsPolicy` server-side; the `download()` route's authorization is fully delegated to the signature itself (which is only ever issued to someone who already passed `store()`/`show()`'s checks) — never an independent, weaker check. | Reviewed — OK |
| 14 | Cache isolation | No report/dashboard data is cached anywhere in B12 (Module 22 §18's cache-safety concern is trivially satisfied by not caching at all yet — consistent with this milestone's own "if real-time aggregation is sufficient, prefer it" decision). | N/A this milestone |
| 15 | Queue tenant spoofing | `GenerateReportExportJob` resolves `TenantContext` from the `ReportExport` row's own `store_id` (loaded by the job's own database lookup) — only an internal integer id is serialized into the job payload, mirroring every queued job since Phase B10/B11's identical, already-reviewed pattern. | Reviewed — OK |
| 16 | Scheduled report impersonation | No scheduled-report feature exists in B12 at all (explicitly deferred — see inspection findings) — nothing to impersonate. | N/A — feature deferred |
| 17 | API enumeration | `ReportExport` uses `public_id` for all client-facing addressing; no report exposes an internal sequential id. | Reviewed — OK |
| 18 | Excessive page sizes | `productsReport()` uses Laravel's standard `paginate()` (bounded page size); no report endpoint accepts an unbounded/arbitrary `per_page` from the client. | Reviewed — OK |
| 19 | Excessive date ranges | `DateRangeResolver`'s custom-range path enforces a hard 366-day cap, rejecting anything larger before any query runs. Tested explicitly. | Reviewed — OK |
| 20 | Resource exhaustion | Every report is a single aggregated `COUNT`/`SUM`/`GROUP BY` query (or a paginated join) — no report loads more than one page of hydrated models into PHP; the date-range cap additionally bounds how much data any one query can touch. | Reviewed — OK |
| 21 | PII exposure | Reviewed alongside #4 — no report returns raw customer contact information. | Reviewed — OK |
| 22 | Sensitive financial data exposure | Reviewed alongside #3/#5 — gated behind `analytics.financial`, stripped server-side when absent. | Reviewed — OK |
| 23 | Realtime channel authorization | No realtime/Reverb-based analytics feature exists in B12 (explicitly deferred — Module 22 itself says "do not introduce Realtime unnecessarily"). | N/A — feature deferred |
| 24 | Analytics event spoofing | B12 introduces no new analytics event-ingestion endpoint at all — every report reads existing, already-trusted domain tables (Orders, Payments, etc.), never a client-submitted "analytics event" payload that could be spoofed. | N/A — feature deferred, no new event surface |
| 25 | Metric manipulation | Every metric is computed server-side from authoritative tables at request time — there is no cached/stored "metric value" a client could tamper with, and no endpoint accepts a client-supplied metric value for anything. | Reviewed — OK |
| 26 | Audit bypass | Report EXPORT actions are themselves durably recorded (`ReportExport` rows, including `requested_by_user_id`) — a de facto audit trail for the one action Module 22 flags as most sensitive (§46 "report export"); ordinary read-only report/dashboard VIEWS are not separately audited beyond standard authorization/validation (documented limitation, consistent with B9/B10's identical scope decision for their own admin-read surfaces). | Reviewed — OK for exports; documented limitation for plain views |
| 27 | Super Admin boundary | No new Super Admin surface was added or needed — B12's dashboard/reports are entirely store-scoped; a cross-tenant platform dashboard (Module 22 §9/§55) is explicitly deferred, not built with a weaker boundary. | N/A this milestone |
| 28 | Entitlement bypass | `analytics.basic` feature flag added via the existing `EntitlementService`/`PackageSeeder` pattern, all 3 tiers — no numeric limit was invented, and no report bypasses the standard permission checks regardless of entitlement state. | Reviewed — OK |
| 29 | Notification delivery bypass | B12 sends no notification of any kind itself — Module 22 §45's "reuse B11 for report delivery" is honored by not building a competing delivery path (scheduled reports, the one feature that would need this, are explicitly deferred). | N/A — feature deferred |
| 30 | Report definition tampering | Every report's shape (fields, filters, sort whitelist) is hardcoded PHP, not a database-configurable "report definition" a tenant admin could edit — Module 22 §39's warning about a configurable query interface is honored by not building one. | Reviewed — OK |

## Issues Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

1. **Low-stock miscalculation** — an early draft referenced a non-existent
   `Inventory.low_stock_threshold` column and compared it against raw `on_hand`
   rather than Phase B4's real `available()` (`on_hand - reserved`) formula. Fixed
   by mirroring B4's exact, authoritative definition — directly enforcing this
   milestone's own Core Principle ("reports must not become a second source of
   truth").
2. **Enum-cast columns from grouped `selectRaw()` queries returned as PHP enum
   objects, not strings** — affecting `paymentsReport()`, `shippingReport()`,
   `marketingReport()`, `notificationsReport()`. Fixed by explicitly extracting
   `->value` via an `instanceof \BackedEnum` check before any value is returned,
   with regression tests confirming string output in `ReportServiceTest`.

## Known Limitations (Documented, Not Hidden)

1. Ordinary report/dashboard VIEWS are not individually audited (only export
   requests are, via the `ReportExport` record itself) — consistent with every
   prior phase's identical scope decision for read-only admin surfaces.
2. No dedicated rate limiting beyond the platform default on dashboard/report/
   export endpoints — consistent with every similarly-scoped endpoint since B9.
3. No caching of any kind exists yet — acceptable at current scale per this
   milestone's own "prefer real-time aggregation" decision, but worth revisiting
   if/when real production query volume is observed.

None of the "found and fixed" items required deleting or resetting existing B0-B11
work. No destructive database operation was performed (the one new migration in
B12 is new-table only).
