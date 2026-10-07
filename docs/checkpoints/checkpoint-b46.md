============================================================
PHASE B46 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B46 — Tax engine (gap G3, owner decision 1 "Tax policy"; Module 11 §28,
Module 05 §43/§75, Module 33 §29, Module 13 §62, Module 14 §36, Module 09
§10–12, Module 29 §57–58/§95–96; SRS CHK-007; Bible §116)

Owner request (2026-10-06): finish what B45 left, then B46; commit and push.

Starting point:
v1.1 at 978023a (B45 follow-up, CI green). tax_total was always 0.

Implementation Summary:
1. tax_classes, tax_rates (country / region / dates / on-off, basis
   points), products.tax_class_id, customer exemption, orders.tax_snapshot
   and prices_include_tax; permission tax.manage. No rate is seeded.
2. TaxCalculator (pure, integers, half-up; inclusive/exclusive, discount
   before/after tax, line/order rounding, shipping) and TaxService
   (settings, address basis, rate lookup, exemption, snapshot).
3. OrderService taxes every order (storefront, staff, replacement);
   CheckoutService pricing shared by checkout and the new
   POST /checkout/summary.
4. Admin: Settings → Tax (settings, classes, rates, "Try it"); product tax
   class; customer exemption (tax.manage); order tax breakdown.
5. Storefront: province field, summary with discount / delivery / tax /
   total before ordering, "Includes …" for inclusive prices; account order
   page; Urdu texts.

Audit and reuse: OrderService as the single tax point; CheckoutService
pricing extracted (no duplication); the refund discount split moved to
App\Support\MoneyAllocation and shared with tax; Module 33 settings.

Found and fixed: generic settings API could change tax.* without tax.manage
or the rate check (now refused); refunds would add tax twice with inclusive
prices; checkout summary race (older answer replacing newer); tax page form
reset losing an unsaved change; checkout asked for a summary of an empty cart.

Tests (2026-10-07):
PHP: 1167 passed (TaxEngineTest 5, Unit TaxCalculatorTest 6).
PHPStan: no errors.
Vitest: 207 passed (tax.test.tsx 3).
ESLint, TypeScript: clean. Production build: passes.

Browser verification — EXECUTED (Chromium, local server, owner MFA,
production build): 7 of 7.
1. Fresh demo-store owner signs in with two-step sign-in.
2. Tax page: "No tax rates come with the platform"; Charge tax disabled.
3. Class Standard, rates Sales tax 17 % (PK) and Provincial 1 % (Punjab);
   tax on.
4. Try it: 1 000 in Punjab → Rs. 180 tax, Rs. 1,180.
5. Storefront checkout (Lahore, Punjab, PK): Tax Rs. 450, Total Rs. 2,950
   before ordering; order placed for Rs. 2,950.
6. Admin order: Sales tax 17 % on Rs. 2,500 = Rs. 425; Provincial 1 % = Rs. 25.
7. 375 px: tax page and checkout fit.
Console: two 401 answers on the guest checkout page come from the customer
session probe (/customer/profile, since B25) — not from B46; left as is.

Owner question (reported, not decided): with tax-inclusive prices, should an
exempt customer pay the net price? Now: no tax recorded, shown price paid.

Not built (b46-tax-engine.md §6): tax on platform invoices (B47), tax
reports, compound rates, tax providers, postal-code rates, catalogue prices
shown with a different tax display than entered.

CI: run on ef8b515 — success, verified 2026-10-07.


------------------------------------------------------------
FOLLOW-UP (2026-10-07) — owner requests 14 and 15
------------------------------------------------------------

Owner request (2026-10-07, while testing): sans-serif fonts only; Business
and Premium product details with ratings and the number sold; Premium
buttons move slightly on hover.

Built (b46-followup-fonts-reviews-motion.md):
1. Fonts: serif fonts removed (list, packages, Boutique → DM Sans/Poppins),
   stored theme configurations migrated, old ones render sans.
2. Reviews: verified-buyer reviews (product_reviews), store moderation
   (Catalog → Reviews: publish, reject, reply, delete), rating summary and
   stars on product pages and cards, AggregateRating, privacy export and
   erasure. Features reviews.product and products.units_sold (Business,
   Premium); permission reviews.manage.
3. Units sold on product pages (orders that count, outside the page cache;
   on/off and a minimum).
4. Premium buttons rise 2 px on hover (mouse only, reduced motion off).

Audit: no review system existed — ratings without one would be invented.
StorefrontCatalog's units-sold query reused; its copy in bestSellers()
removed.

Tests (2026-10-07): PHP 1171 passed (ReviewsAndUnitsSoldTest 4; theme and
privacy tests updated); PHPStan no errors; Vitest 211 passed
(reviews.test.tsx 4); ESLint, TypeScript clean; build passes.

Browser verification — EXECUTED: 7 of 7, no console errors besides the B25
session probe: headings Poppins / text DM Sans; Premium "Add to cart" moves
-2 px on hover; customer buys → "2 sold" becomes "3 sold"; review waits;
owner publishes it on Reviews; product page 5.0 with the review and
"Verified purchase"; stars on the listing card; 375 px fits.

CI: not yet run.

Next (on the owner's word): B47 — billing completion.
