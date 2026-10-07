# B46 — Tax engine

Gap **G3**, owner decision 1 "Tax policy" (2026-09-30): *tax must be
configurable per store and jurisdiction — tax-inclusive and tax-exclusive
pricing, tax classes, configurable rates and applicable regions. No tax rate
may be hardcoded. Pakistan rates are configured only after the legal
requirements are confirmed.*

Specs read before building: Module 11 §28 (tax foundation: product tax
category, customer and shipping location, store configuration, exemptions;
server-authoritative), §61 (checkout API); Module 05 §43 (tax display:
inclusive/exclusive, tax lines, breakdown, location rules), §73–75 (never a
tax amount from the client); Module 33 §29 (tax settings: enabled, display,
inclusive/exclusive, region, provider, rounding, label; no universal rule);
Module 13 §62 (shipping tax); Module 14 §36 (discount before or after tax,
one consistent policy); Module 09 §10–12 (order and line snapshots, totals);
Module 06 §22–24 (tax configuration reference on products); Module 29 §57–58,
§95–96, §110 (rate, amount, jurisdiction, exemption, identifier, category;
historical results preserved; integers; deterministic, versioned rounding);
SRS CHK-007; Project Bible §116 ("explicit and auditable").

## 1. Audit first — what was reused

| Need | Existing code | Decision |
|---|---|---|
| Tax on orders | `orders.tax_total_minor`, `order_items.tax_minor` (B5, always 0) | filled now; no new totals columns |
| One place orders are made | `OrderService::createOrder()` (storefront, staff and replacement orders) | tax is computed **there**, so every kind of order is taxed the same way and no caller can pass a tax amount |
| Shipping and promotions | `CheckoutService` (B8/B9) | pricing extracted into one private `price()`; checkout and the new summary both use it |
| Discount split over lines | `RefundCalculator` (B33) had its own loop | moved to `App\Support\MoneyAllocation::spread()`, used by refunds **and** tax |
| Refunds | line paid = line − share + **tax** | fixed for tax-inclusive prices (the tax is already in the line) |
| Settings with history | Module 33 registry, `ConfigService` | seven `tax.*` settings; the generic settings API no longer lists or changes them (see §5) |
| Platform invoice tax | `config('billing.tax_rate_bps')` in `InvoiceLedger` | unchanged here — Umar Techy's own invoices are B47 (billing completion) |

## 2. Data

Migration `2029_04_01_000001_tax_engine`:

- `tax_classes` (store): name, description, `is_default` — e.g. Standard,
  Reduced, Zero rated. The first class is the default; there is always one.
- `tax_rates` (store): class, name ("Sales tax"), `country` (ISO-2, null = any),
  `region` (null = whole country; matched case-insensitively with the
  address's province), `rate_bps` (1 650 = 16.5 %, at most 100 %),
  `is_active`, `starts_on`, `ends_on`. **No rows are seeded.**
- `products.tax_class_id` (null = the default class).
- `customers.tax_exempt`, `tax_exemption_reference` — set by staff only
  (not mass-assignable).
- `orders.prices_include_tax`, `orders.tax_snapshot` — the result as charged.
- Permission `tax.manage` (Owner, Administrator; granted to existing
  Administrator roles by the migration).

## 3. How tax is worked out

`TaxService::quote()` reads the settings, picks the address, finds the rates,
and hands the arithmetic to `TaxCalculator` (no database, same inputs → same
result).

| Setting | Values (default) | Meaning |
|---|---|---|
| `tax.enabled` | off | no tax until the store turns it on; needs an active rate first |
| `tax.prices_include_tax` | off | on: prices hold the tax, which is taken out (net = price × 10 000 ÷ (10 000 + rates)) and shown; off: tax is added |
| `tax.based_on` | shipping | shipping address, billing address, or the store's own country; falls back shipping → billing → store country |
| `tax.shipping_taxable` | off | delivery taxed with the default class's rates |
| `tax.discount_basis` | before_tax | tax on the discounted amount (order discount spread over lines by amount) or on the full amount |
| `tax.rounding` | line | each line rounded, or each rate rounded once per order and spread back |
| `tax.label` | Tax | the name shoppers see (GST, Sales tax…) |

Rates: every active rate of the product's class that matches the address and
the day applies, and they **add up** (a national rate plus a provincial rate).
Compounding is not supported. Rounding is half up, in integers.

An exempt customer pays no tax; the order records `exempt` and the reference.

### The snapshot kept on every order

`policy_version` (TaxCalculator::POLICY_VERSION), the policy (inclusive,
discount basis, rounding), based on, shipping taxable, label, country,
region, exempt/reference, the breakdown per rate (name, rate, base, tax),
shipping tax, total, time. Later changes to rates or settings never touch it
(Module 29 §58, §96).

### Totals

| Prices | Grand total |
|---|---|
| exclusive | subtotal − discounts + shipping + **tax** |
| inclusive | subtotal − discounts + shipping (the tax is inside; shown as "includes") |

## 4. Where it shows

| Place | What |
|---|---|
| Admin → Settings → **Tax** | settings, classes, rates (country, region, %, dates, on/off), "Try it" calculation (works before tax is on) |
| Admin → product | "Tax class" (when the store has classes) |
| Admin → customer | "Tax" card: exempt + certificate/registration number (tax.manage) |
| Admin → order | tax line with label, "included in prices", or exempt; rate-by-rate breakdown |
| Storefront checkout | province field; `POST /checkout/summary` gives discount, delivery, tax and total before ordering ("Total before delivery" until a delivery option is chosen) |
| Customer account → order | tax line, "Includes …" for inclusive prices, exempt |

Storefront texts are translated into Urdu (the i18n coverage test).

## 5. Found and fixed on the way

- **Settings bypass**: the generic `PUT /store/settings/{key}` would have let
  anyone with `settings.manage` change `tax.*` — skipping `tax.manage` and
  the "a rate before tax is on" rule. Tax keys are now neither listed nor
  changed there (403 `managed_on_tax_page`), rollback included.
- **Refunds with inclusive prices** would have added the tax twice.
- **Checkout summary race**: an older answer (before a delivery option was
  chosen) could replace a newer one; older answers are now ignored.
- **Tax page**: reloading after adding a rate reset the settings form and
  lost an unsaved "Charge tax" tick; the form now resets only when the saved
  values change.

## 6. Not built here

- Tax on Umar Techy's subscription invoices to stores (Module 29 §57) — B47.
- Tax reports (collected per rate and period) — with the analytics work.
- Compound rates, tax-provider integrations (Module 33 §29 "calculation
  provider"), postal-code level rates.
- Inclusive prices for an **exempt** customer: the order records no tax and
  the customer pays the shown price. Whether such customers should instead
  pay the net price is a business/legal question for the owner (reported, not
  decided here).
- Showing product prices "incl./excl. tax" on catalogue pages for a
  different display than entry (Module 05 §43): prices are shown as entered,
  with checkout showing the tax.
