# Phase B12 — Reports, Analytics & Dashboard Architecture (Module 22)

See `docs/development/b12-inspection-findings.md` for the scope decision and the
full Metric Dictionary (every financial term's exact definition, per Module 22's
own Non-Negotiable "document ambiguous metrics" requirement).

## Core Principle: Reports Read, Never Own

B12 introduces zero new business state. Every report method reads directly from
an existing authoritative table (`orders`, `payment_transactions`, `shipments`,
`promotion_usages`, `campaign_recipients`, `notification_messages`, `inventories`)
via server-side SQL aggregation. No prior phase's domain service, state machine,
or entitlement logic was modified — confirmed by direct inspection (zero
`Analytics`/`Report` references in any of `OrderService`, `PaymentService`,
`ShipmentService`, `PromotionService`, `CampaignService`, `NotificationService`,
`InventoryService`, or any of their state machines).

## Metric Dictionary (Summary — Full Table in Inspection Findings)

The single most important artifact of this milestone: every financial number has
exactly one name and one formula. **Revenue** (order value, `SUM(grand_total_minor)`)
is explicitly and permanently DIFFERENT from **Collected Amount** (actual cash,
`SUM(PaymentTransaction.amount_minor)` where `type IN (sale, capture)` and
`status = succeeded`) — a Cash-on-Delivery order counts fully toward Revenue the
moment it's placed, but nothing toward Collected Amount until a courier actually
delivers it and staff record the cash. Both are exposed as separately-named
dashboard fields, never conflated.

## Real-Time Aggregation, No Summary Tables

Per Module 22's own "if real-time aggregation is sufficient, prefer it," B12
builds no materialized/summary analytics table. Every `DashboardService`/
`ReportService` method computes its result live, at request time. If a future
phase's real production load requires pre-aggregation, these exact method
signatures are the natural seam to cache/materialize behind without changing any
caller — documented as the extension point, not built speculatively now.

## Two Bugs Found During This Milestone

1. **Low-stock miscalculation** — an early draft used a non-existent
   `Inventory.low_stock_threshold` column against raw `on_hand`. Phase B4's real,
   authoritative formula (`Inventory::isLowStock()`) is `available()` (`on_hand -
   reserved`) compared against `reorder_point`. Fixed by mirroring B4's exact SQL
   equivalent (`whereNotNull('reorder_point')->whereRaw('(on_hand - reserved) <=
   reorder_point')`) rather than inventing a second, incorrect low-stock
   definition — directly enforcing this milestone's own Core Principle.
2. **Enum-cast columns from `selectRaw()` grouped queries** — `Payment.status`/
   `.method`, `Shipment.status`, `CampaignRecipient.status`,
   `NotificationMessage.channel`/`.status` are all Eloquent-cast to backed enums;
   Laravel still applies these casts to `selectRaw()`'d aggregate result rows.
   An early draft returned these enum instances directly into report arrays
   (which would serialize incorrectly in JSON). Fixed by explicitly extracting
   `->value` via an `instanceof \BackedEnum` check before returning.

## Date Basis Per Report (Never `created_at` Universally)

Each report uses the field that actually answers its question: Orders use
`Order.created_at`; Payments use `PaymentTransaction.created_at` (a refund 5 days
after an order appears in day 5's payment report, not day 1's); Shipping uses
`Shipment.created_at` for volume; Promotions/Marketing use their own ledger's
`created_at`. Full table in inspection findings.

## Date Filters, Timezone, and Anti-Abuse Range Cap

`DateRangeResolver` implements Module 22 §12's exact 10-preset list plus
`custom`, all server-validated against a fixed whitelist (never a client-supplied
arbitrary string reaching SQL). A custom range is capped at 366 days — a
documented anti-abuse guard (Step 9's "prevent extremely expensive unrestricted
queries"), not an invented package/commercial limit. No `Store.timezone` column
exists (the identical gap Phase B10 already documented) — all ranges use UTC/
server time.

## Comparison Periods

Only "previous period of equal length immediately preceding" is implemented
(Module 22 §13 lists three other modes — deferred, see inspection findings). A
period with a zero-value comparison base returns an explicit `null` percentage
change, never a misleading `0%` or a divide-by-zero crash.

## Whitelisted Filters and Sorting (Non-Negotiable — No Client-Supplied SQL)

`ReportService::productsReport()`'s `sortBy` parameter is matched against a fixed
`match()` expression (`'quantity' => 'quantity_sold', 'revenue' => 'revenue_minor'`,
default falls back to the safe default) — an unrecognized value NEVER reaches the
`orderByDesc()` call as a raw client string. No report anywhere in B12 accepts a
client-supplied column name, table name, or raw SQL fragment.

## Role-Based Access — Financial Reports Are Separately Permissioned

`AnalyticsPolicy` distinguishes `analytics.view` (dashboard shape, operational
reports: products/customers/shipping/marketing/notifications/inventory) from the
STRICTER `analytics.financial` (sales/payments/promotions reports, and the
dashboard's own revenue/collected/refunded/AOV fields) — Module 22 §36's own
explicit "revenue... financial information" sensitivity warning. A staff member
with only `analytics.view` sees the dashboard's operational shape with financial
fields entirely stripped server-side (`DashboardController::stripFinancials()`),
never hidden only in the frontend. `analytics.financial` is NOT granted to
Manager by default (high-risk, consistent with B7/B9's identical `payments.refund`/
`promotions.manage`-style precedent for sensitive financial permissions).

## Export Pipeline — Async, Idempotent, Tenant-Scoped, Signed Download

`ReportExportService::requestExport()` is idempotent (existing
`idempotency_key` short-circuits before any job dispatch). `GenerateReportExportJob`
(queued, tenant context resolved from the export row's own `store_id`) writes a
CSV to `storage/app/private` (Laravel's non-public `local` disk) — never a
predictable public path. Download happens ONLY via `URL::temporarySignedRoute()`
(Laravel's own framework-native expiring, tamper-proof URL mechanism — not a
fourth bespoke HMAC scheme, since B7/B8/B11's custom schemes existed specifically
for non-Laravel external recipients, which does not apply here). A forged or
expired signature is rejected by Laravel's own `signed` middleware before the
controller ever runs (tested explicitly).

## API Endpoints Added in B12

| Method | Path | Auth | Permission |
|---|---|---|---|
| GET | `/api/v1/dashboard` | staff | `analytics.view` (financial fields need `analytics.financial`) |
| GET | `/api/v1/reports/sales`, `/payments`, `/promotions` | staff | `analytics.financial` |
| GET | `/api/v1/reports/products`, `/customers`, `/shipping`, `/marketing`, `/notifications`, `/inventory` | staff | `analytics.view` |
| POST | `/api/v1/exports` | staff | `analytics.export` |
| GET | `/api/v1/exports/{id}` | staff | `analytics.export` + tenant ownership |
| GET | `/api/v1/public/report-exports/{id}/download` | signed URL only | N/A (delegated authorization via signature) |

## UI

Not built in B12, matching every backend-focused phase's own precedent.

## Deferred (see inspection findings for the full, explicit list)

SEO/content, theme/interaction, domain, infrastructure, PWA/mobile, AI, affiliate/
reseller analytics (all depend on modules that don't exist), billing analytics
beyond existing entitlement/usage data, cart/checkout/conversion/attribution/
session analytics (no storefront event-tracking system exists), custom report
builder, saved reports, scheduled reports, real-time/Reverb-pushed dashboard
updates, dashboard personalization/role-based layouts, Super Admin platform-wide
dashboard, currency conversion, admin/customer-facing UI.
