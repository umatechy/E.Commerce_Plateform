# Currency: Pakistan first (owner decision 2026-10-03)

## Decision

The platform is built for Pakistan first and will later serve stores
outside Pakistan.

- A store's currency is **PKR**, shown as **Rs.**, unless the store
  chooses another.
- The platform offers **PKR, USD, EUR, GBP, AED, SAR** (the three largest
  currencies and the two Gulf currencies where Pakistani businesses sell
  most). Platform staff keep the list in Platform settings →
  "Supported currencies"; a store picks one in Settings → "Store
  currency".
- Platform billing (what stores pay Umar Techy) defaults to PKR too
  (`BILLING_CURRENCY`).
- Nothing is ever converted between currencies.

## What was there before (inspection)

| Place | Before | Problem |
|---|---|---|
| `platform.supported_currencies` default | `['USD']` | PKR could not even be chosen |
| `store.default_currency` default | `USD` | Every store priced in dollars |
| New store's free pickup rate | `USD` placeholder | — |
| Guest/customer carts | `USD` placeholder (B6) | **Bug:** in a store that set PKR, carts stayed USD, so the store's PKR shipping rates were never offered and checkout failed |
| Product created without a currency | stayed empty | **Bug:** an order for it could not be saved (`orders.currency` is required) |
| Currency inputs (product, promotion, shipping rate, package price) | free text, any 3 letters | A typo ("PRK") made a price no cart could ever match |
| Browser display of PKR | `Intl` gives PKR **0** decimals | **Bug:** ISO 4217 and the server use 2 (paisa). With PKR, 1999 minor units would have shown as "PKR 1,999" instead of Rs. 19.99, and an entered "19.99" would have been refused |
| Emails, SEO data, storefront prices on the server | `minor / 100` | Assumed 2 decimals for every currency (ADR-003 says never assume) |
| `BILLING_CURRENCY` | `USD` | — |

## What changed

| Area | Now |
|---|---|
| `App\Domain\Settings\Services\Currencies` | One place for the default (PKR), the offered list, ISO 4217 decimals, plain and readable amounts, and the validation rule |
| Settings | Defaults PKR / the six; codes upper-cased; the platform list accepts only three-letter codes, each once; a store's currency must be in the list (unchanged rule) |
| Carts | Start in the store's currency (`CartService::storeCurrency()`); the B6 placeholder is gone |
| Products | Without a currency they take the store's; with one, it must be offered |
| Promotions, shipping rates, package prices | Currency must be offered |
| Admin UI | One "Currency" picker (`Components/CurrencyField`) everywhere, fed by the platform list; Store currency setting is a choice, not free text |
| Money display (`lib/money.ts`) | ISO decimals everywhere (same table as the server); PKR written "Rs. 2,500", paisa only when there are some ("Rs. 2,500.50"); "2,500" and "1,25,000" are read as thousands, "12,50" as a decimal comma |
| Server text | Emails, structured data and storefront prices use the currency's own decimals |
| Existing data (migration `2028_05_01_000005`) | Saved platform list gains the six (others kept); a store that never chose a currency **and already has amounts in another currency** is pinned to USD (so its prices keep their meaning, recorded in its settings history); every other such store moves to PKR with its free pickup rate and open carts; products without a currency get their store's |

## Tests

`tests/Feature/Settings/CurrencyDefaultsTest.php` (5): defaults; a PKR
store selling end to end (product without currency → cart → shipping
quote → checkout, order in PKR); only offered currencies accepted and
codes upper-cased (products, store setting, platform list); the shell
offers the list; the migration on old data. `resources/js/lib/money.test.ts`
(5): defaults, ISO decimals for PKR, "Rs." formatting, other currencies,
thousands separators. Checkout tests that use the USD factories now give
their store USD (`TestCase::storeCurrency()`), as a real USD store has.

## Not changed

- One store sells in one currency. A product priced in another currency
  than the store's carts cannot be shipped with the store's rates
  (unchanged B5 rule: one currency per order). Multi-currency selling
  (Module 04/06 future expansion) is not built.
- No exchange rates; Super Admin overview totals add amounts of all
  currencies without converting them (says so on the page).
- A local `.env` that sets `BILLING_CURRENCY=USD` keeps USD for platform
  billing; `.env.example` now says PKR.
