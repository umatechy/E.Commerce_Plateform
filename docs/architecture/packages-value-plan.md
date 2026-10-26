# Packages: honest contents, services, and the features still to build

Owner decisions of 2026-10-26: a store is never sold a feature that is not
built; services go into the packages; AI (recommendations, chatbot) comes
later; loyalty points and advanced analytics are to be built; everything that
can be built is built before the first real customer.

## What the audit found

Feature keys in `PackageSeeder` that no code enforces or provides:

| Key | Package | Status |
|---|---|---|
| `ai.recommendations`, `ai.chatbot` | Premium | Not built — later (owner) |
| `loyalty.points` | Premium | Not built — phase P2 |
| `advanced_analytics.enabled` | Premium | Not built — phase P3 |
| `reports.advanced` | Business | Reports exist but are open to every package — phase P3 gates them |
| `inventory.advanced`, `marketing.advanced`, `integrations.advanced` | Business | Labels only — phase P4 |

The public home page already listed built features only (`PublicPlans::SHOWN_FEATURES`).

## P1 — done

- `App\Domain\Packages\Support\FeatureAvailability::COMING_SOON` is the one
  list of features not built yet. The API marks them (`coming_soon` on each
  entitlement); Super Admin → Packages, the store's Package & billing page and
  the Contents dialog show them apart as "Coming soon", never as included. A
  feature leaves the list in the change that builds it.
- `packages.services` (JSON list, up to 12 lines of 160 characters), edited in
  Super Admin → Packages; first wording in `PackageServices::DEFAULTS` (seeder)
  and in migration `2030_04_06_000001_package_services` (existing packages).
  Shown on the home page pricing, on Super Admin → Packages and on the store's
  Package & billing page.
- Super Admin → Packages lists only included features; a higher tier says
  "Everything in <tier below>, plus:" when it really includes all of it.

## P2 — Loyalty points (Premium) — done

Blueprints: Module 10 §45, Module 15 §45 (points, tiers, rewards, birthday and
repeat-purchase rewards; an auditable, tenant-scoped ledger), Module 14 §76
(loyalty separate from promotion rules; redeemed through a controlled
adjustment — here the existing store-credit ledger). `loyalty.points` left
`FeatureAvailability::COMING_SOON` and is on the public pricing.

**Data** (migration `2030_04_07_000001_loyalty_points`, all store-scoped, all
purged with the store — `StoreDataMap`):
`loyalty_programs` (one per store, the store's own rates), `loyalty_accounts`
(unsigned balance; `lifetime_points` decides the tier), `loyalty_entries`
(immutable ledger, idempotency key per change), `loyalty_order_awards` (what
an order earns), `customers.birth_month`/`birth_day` (day and month only).

**Rules** (`App\Domain\Loyalty`):
- Nothing is proposed: the store enters every rate, and nothing earns points
  until it turns the program on. Only a rate giving back more than the
  customer spends (also at the top tier) is refused. Turning on needs
  `loyalty.points` in the package.
- Points are for customers with an account, on what they pay for products:
  after discounts, without delivery, tax added on top, or what store credit
  paid (`LoyaltyEngine::baseOf`). The tier's bonus is fixed when the order is
  placed.
- `loyalty:follow-up` (every 15 minutes, idempotent, per store under its
  tenant context): new orders get a pending award; an award is earned when
  the order is paid and delivered — all shipments delivered, or the order
  moved to delivered by staff — and the hold is over. A cancelled pending
  order earns nothing. Refunds (payment refunds and returns refunded as
  credit) take back the same share, also after earning; if the points were
  used, what is left is taken and the rest written off — the balance never
  goes negative, and the tier counts all that was taken back. Earned awards
  are followed for 365 days.
- Every Nth earned order brings the store's bonus; once a year, on the
  customer's birthday (store time zone; 29 February on the 28th in other
  years), customers who have earned points before get the birthday points.
  Expiry, if set: the balance lapses after that many days without earning or
  using points.
- Redemption (`LoyaltyRedemption`): whole steps of the store's rate, from its
  minimum, into store credit, in one transaction with both ledger entries
  pointing at each other. It stays possible when the store turns earning off
  or its package changes: customers keep what they earned.
- Staff corrections: `loyalty.adjust` (Administrator, Owner), a reason, step-up,
  audited; they do not raise the tier. `loyalty.view` / `loyalty.manage` for
  marketing roles. Erasure ends the balance and clears the birthday; the data
  export lists every entry; merging customers joins balances, entries and
  awards.

**Screens**: Marketing → Loyalty points (status, plain-language rules, figures
incl. what the points held are worth, members, settings with a live example
and the share of spending that comes back); the customer page's Loyalty card;
the shop's Rewards page (balance and worth, tier progress, one-step
redemption, how it works, history), the account overview card, the birthday
in the profile (only where the store gives birthday points), "Earn N loyalty
points" on product pages, and at checkout the expected points and a one-step
"Use N points for Rs. X". Shop texts in Urdu too.

**Found while building (not changed in P2):** affiliate commissions (B57)
qualify on the order's own status `delivered`, which only staff set; an order
whose shipments are all delivered stays `confirmed`, so a commission that
requires delivery waits until staff move the order. Loyalty accepts both;
the affiliate rule should do the same — proposed as a small follow-up.

## P3 — Advanced analytics (Premium) and advanced reports (Business) — done

Blueprint: Module 22 §19 customer analytics (new vs returning, repeat purchase
rate, lifetime value, retention), §21 cart and checkout analytics, §22
conversion definitions with their denominators, §13 comparative periods;
Rule 24: package entitlements control advanced analytics.
`advanced_analytics.enabled` left `COMING_SOON` and is on the public pricing.

**What each package gets** (`App\Domain\Analytics\Support\ReportAccess`; the
exact split is not in the blueprints — this is the proposal built, for the
owner to change):
- every package: the dashboard and the core reports — sales, products,
  customers, stock;
- Business and Premium (`reports.advanced`, Module 04 §8 "Advanced Reports"):
  payments, delivery, discounts, campaigns, customer messages and the new
  **categories & brands** report (§18: revenue, units, orders by main category
  and by brand, as the taxonomy is now — orders keep no category snapshot);
- Premium (`advanced_analytics.enabled`): Reports → **Analytics**.

The API refuses a report outside the package with `403 feature_not_entitled`;
the page shows the package notice instead of asking. Exports follow the same
rules. **Fixed on the way:** an export of a money report (sales, payments,
discounts) only checked `analytics.export`, so staff without
`analytics.financial` could download what they may not view; exports now ask
for the report's own permission too.

**Analytics** (`AdvancedAnalyticsService`, `GET /analytics/overview`,
`compare_with` = `previous_period` | `previous_year`; `analytics.view`, money
figures only with `analytics.financial`, null otherwise):
- KPIs against the comparison period, named with the result (§13): net sales,
  orders, average order, customers who ordered, new and returning customers;
  a change with no earlier figure is null, never 0%.
- Where the money comes from (§15): products sold − discounts − refunds = net
  sales; delivery and tax; orders total. Refunds are those made in the period
  (payment refunds and returns refunded as store credit). Only the store's
  currency (§14).
- Who buys (§19): new (first order ever in the period), returning, guests by
  email; orders with an account vs guest; repeat customers; orders per
  customer; average lifetime spend.
- Retention cohorts: customers by month of first order (store time zone), the
  share ordering again in each of the next five months.
- From cart to order (§21–22): carts with a product started in the period, the
  share that became an order (denominator stated), carts left untouched for
  24 h, online payment failures.
- Products (§17): in stock but not sold in the period; return rate per product
  (units accepted back ÷ units sold).
- Each section has "How is this counted?" with its definition.

**Not measured, said on the page:** product views and checkout visits are not
recorded, so no view-to-order or checkout conversion is shown (the cart status
`checkout_started` exists but nothing sets it). Saved, scheduled and custom
reports (§39–42) are not built.

## P4 — Business: advanced inventory, marketing and integrations — done

All three left `COMING_SOON` and are on the public pricing. Only
`ai.recommendations` and `ai.chatbot` remain "coming soon" (owner: AI later).

**P4a — advanced inventory** (`inventory.advanced`; Module 08 §71 Business:
transfers, purchase receiving, advanced stock reports). Products → Stock
planning, with the existing `inventory.view` / `inventory.adjust`:
- *What to order*: per product over all locations — a day's sales from real
  orders over a chosen period, days left, and a suggested quantity for the days
  to cover, less what is available and on its way (at least the reorder
  quantity at the reorder point). The arithmetic is on the page; it is not a
  forecast model.
- *Received stock* (`stock_receipts`): supplier, delivery note, location,
  products; `purchase_in` movements. Quantities only — no costing (owner
  decision).
- *Transfers* (`stock_transfers`): sent (`transfer_out`, shown as incoming at
  the destination), received (`transfer_in`) or cancelled (back to the
  source). Stock reserved for orders cannot leave.
- Multiple locations stay `inventory.multi_warehouse` (B4).

**P4b — advanced marketing** (`marketing.advanced`; Module 15 §29–44, §92
Business "Automation"). Marketing → Automations: welcome after sign-up,
thank-you after delivery, win-back when the last order is old enough,
birthday. Each off until turned on, with its own delay, wording and optional
coupon. `marketing:automations` hourly; marketing email through the
notification pipeline — consent, customer standing, suppression, unsubscribe
link; once per occasion; not within a day of another marketing email; counts
from when it was turned on.

**P4c — advanced integrations** (`integrations.advanced`; Module 04 §54–55).
Installing an app from the marketplace needs it (with `integrations.api` when
the app reads store data, as before); core payment and delivery stay with
every package. Apps already installed keep working after a package change.

**For the owner to decide (not changed):** Module 15 §92 lists segments,
scheduled campaigns and WhatsApp/SMS as Business; today every package has
them (B10, B50). Gating them would take features away from Basic stores, so it
is left for the owner.
