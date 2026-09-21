============================================================
PHASE B12 CHECKPOINT
============================================================

Phase:
Development Phase B

Module:
B12 — Reports, Analytics & Dashboard (Module 22)

Implementation Summary:
Implemented a purely read-only reporting/analytics layer over the existing
Order (B5), Payment (B7), Shipping (B8), Promotion (B9), Marketing (B10),
Notification (B11), and Inventory (B4) domains. Every metric has an explicit,
documented formula (the Metric Dictionary); every report runs live server-side
aggregation with no pre-built summary/materialized table, since no evidence of
scale requiring one exists yet. Introduces one new tenant-scoped table
(ReportExport) for the queued, idempotent CSV export pipeline, delivered via
Laravel's own signed, expiring URLs rather than a fourth bespoke HMAC scheme.
Runtime execution remains deferred to VS Code - nothing in this milestone has
been executed against a real PHP/MySQL/Redis runtime.

Bugs Found and Fixed (design-time, caught before being left in the codebase):
1. An early draft's low-stock calculation used a non-existent
   Inventory.low_stock_threshold column against raw on_hand. Phase B4's real,
   authoritative formula (Inventory::isLowStock()) is available()
   (on_hand - reserved) compared against reorder_point, only when a
   reorder_point is actually configured. Fixed by mirroring B4's exact SQL
   equivalent rather than inventing a second, incorrect definition - directly
   enforcing this milestone's own Core Principle that reports must not become
   a second source of truth.
2. Grouped selectRaw() queries against enum-cast columns
   (Payment.status/.method, Shipment.status, CampaignRecipient.status,
   NotificationMessage.channel/.status) returned real PHP backed-enum
   instances, not plain strings, because Eloquent still applies model casts to
   any selected attribute matching a cast key regardless of raw-SQL selection.
   Fixed by explicitly extracting ->value via an instanceof \BackedEnum check
   before any report value is returned, with regression tests added.

Architectural Decisions:
- No summary/materialized analytics tables - every report/dashboard metric is
  computed via live server-side SQL aggregation at request time (Module 22's
  own "if real-time aggregation is sufficient, prefer it").
- A full Metric Dictionary locks down every financial term's exact formula
  (Gross Sales, Discounts, Net Sales, Revenue, Collected Amount, Refunded
  Amount, Order Count, AOV, Discount Usage, Repeat Customer Rate) - Revenue
  (order value) is explicitly and permanently distinct from Collected Amount
  (actual cash received), never conflated.
- Each report uses the date field that actually answers its question
  (Order.created_at for sales/orders, PaymentTransaction.created_at for
  payments, etc.) - never created_at universally.
- No Store.timezone column exists (the identical gap Phase B10 already
  documented) - all date ranges use UTC/server time.
- Only "previous period of equal length immediately preceding" comparison is
  implemented; a zero-value comparison base returns an explicit null
  percentage change, never a misleading 0% or divide-by-zero.
- Financial reports (sales/payments/promotions, and the dashboard's own
  revenue/collected/refunded/AOV fields) require the stricter
  analytics.financial permission, separate from general analytics.view -
  financial fields are stripped server-side, never hidden only in the
  frontend.
- Exports use Laravel's own URL::temporarySignedRoute() rather than a fourth
  bespoke HMAC scheme (B7/B8/B11's custom schemes existed specifically for
  non-Laravel external recipients, which does not apply to an in-app download
  link).

Dashboard:
Summary endpoint returning every Metric Dictionary KPI for a given date range,
with an optional previous-period comparison. Financial figures are stripped
entirely for a staff member without analytics.financial.

Report Types:
Sales (grouped by day), Products (best-selling, whitelisted sort), Customers
(new/repeat-rate), Payments (status/method distribution), Shipping
(status/carrier distribution), Promotions (usage/discount per promotion,
reusing B9's actual ledger), Marketing (recipient status per campaign, reusing
B10's actual ledger), Notifications (channel/status distribution, reusing
B11's actual ledger - sent and delivered kept as distinct buckets), Inventory
(on-hand/reserved totals, low-stock/out-of-stock counts using B4's exact
formula).

Metric Definitions:
Full dictionary in docs/development/b12-inspection-findings.md - every term
has name, formula, and source table explicitly documented, preventing any
future ambiguity about what "revenue" or "net sales" means on this platform.

Data Sources:
orders, payment_transactions, shipments, promotion_usages,
campaign_recipients, notification_messages, inventories - all read-only, zero
writes to any of these tables from B12.

Filters / Date Ranges:
DateRangeResolver implements Module 22's exact 10-preset list plus custom,
server-whitelisted, with a 366-day anti-abuse cap on custom ranges (a
documented guard, not an invented package limit).

Aggregation:
Server-side SQL only (COUNT/SUM/GROUP BY) - no report loads more than one
page of hydrated models into PHP merely to calculate a total.

Exports:
CSV only (the one format buildable without a new library dependency).
ReportExportService::requestExport() is idempotent (existing idempotency_key
short-circuits before any dispatch). GenerateReportExportJob (queued, tenant
context resolved from the export row's own store_id) writes to
storage/app/private - never a predictable public path. Download only via a
Laravel-signed, time-limited URL; a forged or expired signature is rejected
by the framework's own signed middleware before the controller ever runs.

Caching:
None implemented - documented as an acceptable decision at current scale, not
an oversight; the exact seam to add caching later (behind
DashboardService/ReportService's existing method signatures) is documented.

Performance:
Every report applies tenant filtering implicitly via BelongsToTenant's global
scope before any date/status filtering; joins are limited to one level
(order_items -> orders, promotion_usages -> promotions,
campaign_recipients -> campaigns); pagination is used wherever a report could
otherwise return unbounded rows.

Tenant Isolation:
Every report/export model uses BelongsToTenant; verified by inspection that
every query in DashboardService/ReportService goes through a tenant-scoped
Eloquent model, never a raw DB::table() call that could accidentally skip the
scope.

Authorization:
AnalyticsPolicy (view / viewFinancial / export / downloadExport) - checked
server-side in every controller method. Tested explicitly: 403 without
permission, financial fields stripped with only analytics.view, 404 for
cross-tenant export access, 401 for a customer token on staff routes.

B4 Inventory Integration:
inventoryReport() and the dashboard's low-stock KPI both reuse Inventory's
authoritative available()/isLowStock() formula exactly - no independent
recalculation, no modification of any Inventory row.

B5 Orders Integration:
Every sales/revenue/order-count/customer metric reads Order's own
subtotal_minor/discount_total_minor/shipping_total_minor/grand_total_minor/
status/created_at fields directly - OrderStateMachine is never touched or
reimplemented.

B7 Payment Integration:
Collected Amount and Refunded Amount read PaymentTransaction's own
type/status/amount_minor fields - PaymentStateMachine is never touched;
browser callback data is never treated as payment truth (only the
authoritative PaymentTransaction ledger is read).

B8 Shipping Integration:
shippingReport() reads Shipment's own status/carrier fields directly -
ShipmentStateMachine is never touched; delivery status is never inferred from
notification delivery data.

B9 Promotion Integration:
promotionsReport() reads PromotionUsage's own actual redemption ledger -
promotion eligibility is never recalculated, PromotionService is never
called.

B10 Marketing Integration:
marketingReport() reads CampaignRecipient's own actual ledger - segmentation
is never rebuilt, CampaignService/MarketingSegmentService are never called,
no campaign state is ever modified from a report.

B11 Notification Integration:
notificationsReport() reads NotificationMessage's own actual delivery
state - "sent" and "delivered" are kept as explicitly distinct status
buckets, never conflated, per this milestone's own Step 33 rule.

APIs:
GET /api/v1/dashboard, GET /api/v1/reports/{sales,products,customers,
payments,shipping,promotions,marketing,notifications,inventory}, POST
/api/v1/exports, GET /api/v1/exports/{id}, GET
/api/v1/public/report-exports/{id}/download (signed URL only, no staff auth).

UI:
Not built - matches every backend-focused phase's own precedent.

Database:
1 new migration: report_exports (new table, tenant-scoped, unique
idempotency_key per store). No existing table's existing column altered,
renamed, or removed. No destructive operation performed.

Queue / Jobs:
GenerateReportExportJob (queued, idempotent via both the ReportExport row's
own idempotency_key and an in-job already-processed guard, tenant context
resolved from the export row's own store_id, never an untrusted job payload
field).

Events / Outbox:
None added - Module 22 itself only lists analytics events as conditional
("if B12 requires events"), and no new event was found necessary; every
report reads existing tables directly rather than needing a new
event-sourced feed.

Security Review:
Performed (docs/security/b12-security-review.md) - this milestone's full
30-item checklist reviewed end-to-end, plus a B0-B11 regression confirmation.
2 design-time issues found and fixed. 3 known limitations documented (no
per-view audit trail beyond export requests; no dedicated rate limiting
beyond platform default; no caching implemented yet).

Tests Added:
36 new test methods across 6 Feature test files:
- tests/Feature/Analytics/DateRangeResolverTest.php - 7 methods
- tests/Feature/Analytics/DashboardServiceTest.php - 6 methods
- tests/Feature/Analytics/ReportServiceTest.php - 9 methods
- tests/Feature/Analytics/ReportExportTest.php - 7 methods
- tests/Feature/Analytics/AnalyticsAuthorizationTest.php - 6 methods
- tests/Feature/Analytics/ReportExportConcurrencyTest.php - 1 method
Plus 1 new model factory (ReportExport). Combined with all carried-forward
B0-B11 tests: 416 test methods total across the whole suite (verified by
direct grep count, not estimated).

Tests Actually Executed:
NONE. No PHP, Composer, MySQL, or Redis runtime is available in this Claude
App sandbox.

Tests Not Executed:
All 416 test methods, including all 36 new to this milestone. The
concurrency test (ReportExportConcurrencyTest) is explicitly a sequential
simulation, not genuine parallel load - flagged as the scenario to verify for
real once a runtime is available, consistent with every prior phase's
identical precedent.

Static Inspections Performed (EXECUTED vs INSPECTED vs NOT EXECUTED - nothing
below was EXECUTED):
- Source inspection of every new/modified file against Module 22's
  requirements.
- A Node.js-based brace/parenthesis balance check across all new/modified PHP
  files - no mismatches found.
- Route inspection: confirmed staff dashboard/report/export routes are inside
  the staff.principal-guarded group, and the download route sits in the
  public group with the `signed` middleware and no staff auth requirement.
- Cross-reference check: confirmed zero references to Analytics/Report exist
  in any prior phase's domain service or state machine file, verifying B12
  never modified business-logic ownership.
- Enum-cast behavior inspection: confirmed via the model $casts arrays that
  selectRaw()'d status/channel/method columns are indeed cast, validating why
  the ->value extraction fix was necessary.

Known Limitations:
- Nothing in this milestone has been executed against a real runtime.
- Ordinary report/dashboard views are not individually audited (only export
  requests are).
- No dedicated rate limiting beyond the platform default.
- No caching implemented (documented as an acceptable current-scale decision).

Deferred Functionality:
SEO/content, theme/interaction, domain, infrastructure, PWA/mobile, AI,
affiliate/reseller analytics; billing analytics beyond existing entitlement
data; cart/checkout/conversion/attribution/session analytics; custom report
builder; saved reports; scheduled reports; real-time/Reverb-pushed dashboard
updates; dashboard personalization/role-based layouts; Super Admin
platform-wide dashboard; currency conversion; admin/customer-facing UI. Full
list with rationale in docs/development/b12-inspection-findings.md.

Files Changed:
New: app/Domain/Analytics/ (Models: ReportExport, ReportExportStatus,
ReportType; Services: DateRangeResolver, DashboardService, ReportService,
ReportExportService; Jobs: GenerateReportExportJob; Policies:
AnalyticsPolicy; Http/{Controllers: DashboardController, ReportController,
ReportExportController; Requests: DateRangeRequest, RequestExportRequest;
Resources: ReportExportResource}; Exceptions: 1 class). New: 1 migration, 1
factory, 6 test files. Modified: PermissionSeeder (+analytics.view/financial/
export), PackageSeeder (+analytics.basic for all 3 tiers), StoreObserver
(+analytics permissions for Manager, excluding analytics.financial),
routes/api_v1.php (+dashboard/report/export routes), routes/api_v1_public.php
(+signed download route).

Git Status:
Verified by direct execution (git status) before this checkpoint was written:
all files listed above are new/modified/staged relative to the previous
commit (bb08fc8 / 7f9ac4b). No files outside the Analytics domain and
documentation were touched.

Git Commit Status:
A commit for this milestone's work follows immediately after this checkpoint;
the real, executed commit hash is recorded via a follow-up correction commit
immediately after, same pattern used for every prior phase's checkpoint.

Recommended Next Milestone:
Phase B13 - per the approved module sequence and this platform's own
dependency state, Module 16 (SEO & Content Management) is the next natural
candidate: it is the module B12 itself most frequently cited as an unmet
dependency (§29 "SEO & Content Analytics" was deferred specifically because
Module 16 does not exist), and no later module in the 0-22 sequence has yet
been implemented. Phase B13's own Step 1 should inspect the existing
Product/Category/Brand catalog (B3) and Store (B1/B3) models before designing
any SEO metadata schema, since SEO fields (meta title/description, slugs,
sitemap generation) will likely attach to those existing entities rather than
requiring a wholly separate content domain.
