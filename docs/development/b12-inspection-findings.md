# Phase B12 — Step 1: Inspection + Scope Decision (Reports, Analytics & Dashboard: Module 22)

## Inspection of Existing Code

- No existing reporting/analytics/dashboard implementation exists anywhere in the
  repository — B12 is a clean addition.
- `Order` (B5) — `subtotal_minor`, `discount_total_minor`, `shipping_total_minor`,
  `grand_total_minor`, `status`, `payment_status`, `fulfillment_status`,
  `created_at` are all reused directly as the authoritative source for every
  order/sales metric. B12 writes nothing to this table.
- `PaymentTransaction` (B7) — reused directly for "collected amount" (actual cash
  received) and refund metrics, distinct from Order-value-based "revenue" (see
  "Metric Dictionary" below) — Order and Payment answer genuinely different
  questions and B12 keeps them separately named rather than conflating them.
- `Shipment`/`ShipmentTrackingEvent` (B8), `PromotionUsage` (B9),
  `CampaignRecipient` (B10), `NotificationMessage`/`NotificationDeliveryAttempt`
  (B11), `Inventory` (B4), `UsageCounter`/`EntitlementService` (B2) — all reused
  directly as read-only aggregation sources. B12 introduces no duplicate state
  machine, no duplicate promotion/segmentation/delivery logic, and modifies none
  of these tables.
- `Store` (B1/B3) has no timezone column — the SAME gap Phase B10 already found
  and documented a workaround for (UTC-only scheduling). Module 22 §12's "tenant
  reports must use store timezone by default" cannot be honored precisely for the
  same reason; B12 makes the identical documented simplification (server/UTC time
  throughout), not a new gap.
- No regressions found in B0-B11 during inspection. B12 adds no domain-service
  method to any prior phase's business logic — every existing state machine,
  entitlement check, and delivery mechanism is used exactly as-is, read-only.

## Architectural Decision — Real-Time Aggregation, No Summary/Materialized Tables (Module 22 §22/§48)

Module 22 explicitly says: "If real-time aggregation is sufficient, prefer it...
Do not create a summary table simply because it appears convenient." At this
platform's actual current scale (no production traffic, no evidence of query-
volume pressure), every B12 report is computed via live, server-side SQL
aggregation (`COUNT`/`SUM`/`GROUP BY`) directly against the authoritative tables
at request time — **no pre-aggregated analytics summary table is built**. This is
a deliberate, reviewed decision, not an oversight: building a summary-table
pipeline (source → job → summary → dashboard) without an actual performance
problem to solve would be exactly the premature complexity Module 22 itself warns
against. If a future phase's real production load requires it, the
`ReportQueryService` methods below are the natural seam to add caching/
materialization behind, without changing any caller.

## Architectural Decision — Metric Dictionary (Module 22 §7/§15, Non-Negotiable: "Document ambiguous metrics")

Every financial metric name below is fixed and MUST NOT be silently redefined by
a future phase without updating this dictionary:

| Metric | Definition | Source |
|---|---|---|
| **Gross Sales** | `SUM(Order.subtotal_minor)` — pre-discount, pre-shipping item value | `orders` |
| **Discounts** | `SUM(Order.discount_total_minor)` | `orders` |
| **Shipping Charged** | `SUM(Order.shipping_total_minor)` | `orders` |
| **Net Sales** | Gross Sales − Discounts (matches Module 22 §15's own worked example exactly; Tax is always 0 — no Tax module exists yet, per every prior phase's identical documented deferral) | `orders` |
| **Revenue** (a.k.a. Grand Total Value) | `SUM(Order.grand_total_minor)` = Net Sales + Shipping Charged. **This is order-VALUE, not necessarily cash collected** — a Cash-on-Delivery order not yet paid still counts here, because it is a real, confirmed commercial transaction (Order is authoritative for commerce, per Module 22's own Core Principle) | `orders` |
| **Collected Amount** | `SUM(PaymentTransaction.amount_minor)` WHERE `type IN (sale, capture)` AND `status = succeeded` — actual cash/payment-provider-confirmed receipts, a DIFFERENT, separately-named number from Revenue | `payment_transactions` |
| **Refunded Amount** | `SUM(PaymentTransaction.amount_minor)` WHERE `type IN (refund, partial_refund)` AND `status = succeeded` | `payment_transactions` |
| **Order Count** | `COUNT(Order)` WHERE `status != cancelled` | `orders` |
| **Average Order Value (AOV)** | Revenue ÷ Order Count (0 orders → explicit `null`, never a divide-by-zero 0) | derived |
| **Discount Usage** | `COUNT(PromotionUsage)` and `SUM(PromotionUsage.discount_amount_minor)`, grouped by `promotion_id` | `promotion_usages` |
| **Repeat Customer Rate** | (Customers with `total_orders_count >= 2`) ÷ (Customers with `total_orders_count >= 1`) in the period — reuses Phase B10's own `total_orders_count` metric definition from `MarketingSegmentService`, not a newly invented formula | `orders` + `customers` |

All monetary values remain integer minor units throughout every report/export
(ADR-003, unchanged) — never converted to float for aggregation, only formatted
to major units at the final JSON-serialization boundary.

## Architectural Decision — Date Basis Per Report (Module 22 §8, Non-Negotiable: "Do not use created_at for every report automatically")

- **Order/Sales/Product/Customer reports** — `Order.created_at` (when the
  commercial transaction was made).
- **Payment reports** — `PaymentTransaction.created_at` (when the financial event
  actually occurred — a payment initiated on day 1 and refunded on day 5 appears
  in each day's payment report under its own transaction's real date, not the
  order's creation date).
- **Shipping reports** — `Shipment.created_at` (when fulfillment began) for
  volume; `ShipmentTrackingEvent.occurred_at` for delivery-time calculations.
- **Promotion reports** — `PromotionUsage.created_at` (when the discount was
  actually redeemed, i.e. order-created time by construction).
- **Marketing reports** — `CampaignRecipient.created_at`.
- **Notification reports** — `NotificationMessage.created_at` for volume,
  `NotificationDeliveryAttempt.occurred_at` for delivery-outcome timing.

## Architectural Decision — Timezone (Reuses B10's Identical Documented Gap)

No `Store.timezone` column exists (Tenancy's domain, not touched by B12). All
date-range filters and report date-grouping use UTC/server time exclusively —
documented as a scope simplification, identical in nature and cause to Phase
B10's own `Campaign.scheduled_at` decision. "Today"/"this week"/etc. are computed
against `now()` in server time.

## Architectural Decision — Comparison Periods (Module 22 §13, One Mode Only)

Only **"previous period of equal length immediately preceding the current
range"** is implemented (e.g. a 7-day current range compares against the 7 days
immediately before it). "Previous year" / "same period last year" / "custom
comparison" (the spec's other three listed options) are NOT built — this
platform has no meaningful multi-year historical data yet, and building three
comparison modes without any real usage to validate them against would be
speculative. A period with zero comparable data returns an explicit `null`
percentage-change, never a misleading `0%` or a divide-by-zero.

## Architectural Decision — Exports Use Laravel's Own Signed URLs, Not a New HMAC Scheme

Unlike B7/B8/B11's webhook/unsubscribe signatures (each needed a custom scheme
because the recipient was an external, non-Laravel party), an export download is
requested by a Laravel-application-issued, browser-following link — Laravel's own
built-in `URL::temporarySignedRoute()` mechanism (framework-native expiring,
tamper-proof URLs) is the correct, non-duplicative tool, not a fourth bespoke HMAC
scheme. CSV is the only export format implemented (Module 22 §43 lists CSV/XLSX/
PDF as options — no library dependency for XLSX/PDF generation exists in this
environment, and CSV alone satisfies "implement only formats explicitly
required" once the specification gives no single mandatory format).

## Scope Decision (Module 22 spans 74 sections — same discipline as B8-B11)

**B12 implements**: a Dashboard summary endpoint (KPIs per the Metric Dictionary
above), six report types (Sales/Orders, Products, Customers, Payments, Shipping,
Promotions, Marketing, Notifications, Inventory — see "Report Types" in the
architecture doc), date-range filtering (today/yesterday/last_7_days/last_30_days/
this_week/last_week/this_month/last_month/this_quarter/this_year/custom, each
server-validated with a maximum 366-day custom-range cap — a documented anti-abuse
guard, not an invented package limit), one comparison-period mode, whitelisted
filters/sorting (never a client-supplied column or raw SQL), a queued, idempotent,
tenant-scoped CSV export pipeline with Laravel-signed expiring download URLs, and
staff-facing APIs.

**Explicitly deferred** (named so nothing is silently dropped, given this
module's 74-section, many-integration-dependent scope):
- **SEO/content analytics (§29)** — Module 16 (SEO & Content Management) does not
  exist yet; there is no data source to aggregate.
- **Theme/interaction analytics (§30), domain analytics (§31), infrastructure
  analytics (§32), PWA/mobile analytics (§35), AI analytics (§36), affiliate/
  reseller analytics (§37)** — all depend on modules (Theme/Domain Management/
  Infrastructure Monitoring/PWA/AI Features/Affiliate Program) that do not exist
  anywhere in this codebase.
- **Billing analytics (§34)** beyond what B2's existing `EntitlementService`/
  `UsageCounter` already expose — Module 29 (Billing, Invoices & Renewals) has not
  been built.
- **Cart & checkout / conversion analytics (§21-22)** — no storefront page-view/
  funnel-tracking event system exists (Module 22 §23-24's "Analytics Events"
  concept is not built as a NEW event bus per this milestone's own explicit "do
  not create a duplicate event bus" instruction; B12 only consumes the EXISTING
  outbox-recorded business events, which do not include page-view/add-to-cart/
  checkout-initiation telemetry — no such events were ever emitted by B6).
- **Custom report builder (§39), saved reports (§41), scheduled reports (§42)** —
  Module 22 explicitly makes these conditional ("if Module 22 requires... if not
  defined, do not implement them"); none is defined with enough concrete detail
  (no specific schedule cadence, recipient model, or builder UI contract given) to
  build safely without inventing the missing specification. The report/export
  infrastructure built here (whitelisted `ReportDefinition`-style query methods)
  is the correct seam for a future phase to add scheduling/saving onto without
  restructuring anything.
- **Attribution (§51), session/traffic analytics (§52)** — no click-tracking or
  session-identity system exists to attribute against.
- **Real-time/Reverb-based live dashboard updates (§42-43 "Realtime Analytics")**
  — the dashboard is request-driven (manual/periodic client refresh), not
  broadcast-pushed; no evidence Reverb is actually configured/needed for this
  scope, and Module 22 itself says "do not introduce Realtime unnecessarily."
- **Dashboard personalization, role-based dashboard LAYOUTS (§53-54)** beyond the
  underlying report-level RBAC already enforced — every report endpoint checks
  permissions server-side; a configurable per-user dashboard layout is a UI-only
  concern with no backend built for it here (consistent with every backend-focused
  phase's precedent of no frontend).
- **Super Admin platform-wide dashboard (§55)** — B12's dashboard/reports are all
  store-scoped; a cross-tenant platform aggregate view is a distinct, higher-risk
  surface (Module 22 §9's own "Platform Analytics") deferred pending explicit
  Super Admin reporting requirements beyond what this milestone's prompt gives.
- **Currency conversion (§14)** — this platform has no multi-currency order
  support yet (every prior phase's own single-currency-per-store precedent since
  B2); "Support future multi-currency foundations" is honored by never mixing
  currencies within one report (documented), not by building a conversion engine.
- **Admin/customer-facing UI (React components)** — matches every backend-focused
  phase's own precedent.

None of these are abandoned — each is named so Phase B13+'s own Step 1 inspection
finds this documented list.

## Bug Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

An early draft of `DashboardService::summary()`'s low-stock count used a
non-existent `Inventory.low_stock_threshold` column via `whereColumn('on_hand',
'<=', 'low_stock_threshold')`. Phase B4's actual, authoritative low-stock
definition (`Inventory::isLowStock()`) uses a DIFFERENT formula entirely:
`available()` (`on_hand - reserved`, not raw `on_hand`) compared against a
`reorder_point` column, and only when `reorder_point` is actually set. Caught
during implementation before being left in the codebase — fixed by mirroring
B4's exact formula via `whereNotNull('reorder_point')->whereRaw('(on_hand -
reserved) <= reorder_point')`, reusing B4's authoritative definition instead of
inventing a second, incorrect one (this milestone's own Core Principle: "reports
must not become a second source of truth").

## Second Bug Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

An early draft of `ReportService`'s grouped-by-status queries (`paymentsReport()`,
`shippingReport()`, `marketingReport()`, `notificationsReport()`) assumed
`selectRaw()`'d enum-cast columns (e.g. `Payment.status`, `Payment.method`) would
come back as plain strings on the hydrated result rows. Because these queries are
still built via `Model::query()`, Eloquent applies the model's own `$casts` to any
attribute name matching a cast key — including ones selected via `selectRaw()` —
so `$row->status` actually resolves to a real backed-enum instance (e.g.
`PaymentStatus::Paid`), not a string. Mapping this directly into a report array
would have serialized as an internal PHP enum representation rather than its
value in the JSON response. Fixed by explicitly checking `instanceof \BackedEnum`
and extracting `->value` before it is ever returned.
