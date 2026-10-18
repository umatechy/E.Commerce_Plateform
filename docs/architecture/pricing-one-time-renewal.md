# Package pricing: one-time price, then yearly renewal

Owner decision, 2026-10-17. A package is bought with a one-time price that
includes the first year. From the second year the store renews every year at
the yearly price. Umar Techy can change both prices later.

| Package  | One-time (includes year 1) | Yearly renewal |
|----------|----------------------------|----------------|
| Basic    | Rs. 20,000                 | Rs. 6,000      |
| Business | Rs. 45,000                 | Rs. 8,000      |
| Premium  | Rs. 60,000                 | Rs. 10,000     |

These figures are data, not code. Production sets them in **Super Admin →
Platform billing → Prices**: Billed *Yearly*, currency *PKR*, the renewal price,
and the optional *One-time price (first period)*.

## How it works

- `package_prices.first_period_amount_minor` is nullable.
  - Null: every period is charged `amount_minor`, as before.
  - Set: it replaces `amount_minor` for a subscription's first billed period only.
- `InvoiceLedger::isFirstPeriod()` decides which period is the first one. A period is first when the subscription has no invoice other than voided ones.
  - A voided first invoice does not use up the one-time price.
  - A store coming back after a lapse renews at the yearly price. It does not pay the one-time price again.
- The first invoice line reads: "{Package} package, one-time price including {start} to {end}".
- Reseller partner discounts (`ResellerPricing`) apply to the one-time price as well. The partner portal shows both prices.
- The platform setting `billing.default_interval` ("How often stores renew") sets the interval new stores start on.
  - Values: `yearly` (the default) or `monthly`.
  - A store keeps the interval it started with.
- The public home page shows the price in that interval and in `config('billing.currency')`. When a one-time price is set, it shows "Rs. 20,000 one-time payment — includes the first year, then Rs. 6,000 / year renewal".

## Known limits

- An upgrade during the first billed year is measured on the one-time prices, by the time left (2026-10-18, `PlanChangeService`): what the new package costs for its first period, less what is left of the one-time price paid. After the first year, and for a next period already invoiced, upgrades use the renewal prices.
  - Example: half a year left, Basic → Business = (45,000 − 20,000) × ½ = Rs 12,500.
  - Charging the full difference instead, whatever the time left, would be a different rule. It is an owner decision.
- The free trial still comes before the first paid period. The one-time invoice is issued a week before the trial ends, like any first invoice.
