# B46 follow-up — sans-serif fonts, ratings and units sold, Premium button motion

Owner requests of 2026-10-07 (recorded as owner decisions 14 and 15 in the
gap matrix), made while testing the storefront:

1. "Font sirf sans-serif use krny hen."
2. Business and Premium: ratings and the number sold with the product details.
3. Premium: a slight movement on button hover.

Specs read: Module 17 §8 (typography: self-hosted, privacy-aware fonts);
Module 05 §14 (rating on product cards), §27 (product reviews: rating, text,
customer name, verified purchase indicator, moderation, status,
tenant-scoped), §19 (sorting by rating), §55 (aggregate rating structured
data); Module 10 §31 (blocked customers may not review); Module 15 §32
(purchase-based reviews distinguished from marketing); Module 18 §13 (hover:
button elevation; never needed for touch), §27 (reduced motion); Module 32
(personal data export and erasure).

## 1. Audit first

| Need | Existing code | Decision |
|---|---|---|
| Fonts | `ThemeOptions::FONTS` (6 sans + 4 serif), Boutique used Lora / Playfair Display, `@fontsource` packages | serif fonts removed from the list and the packages; Boutique → DM Sans / Poppins; stored configurations migrated; any old configuration still holding a serif font renders its sans replacement (`RETIRED_FONTS`) |
| Ratings | **nothing** — no reviews existed | a real review system was needed: showing ratings without reviews would be invented data |
| Units sold | `StorefrontCatalog::unitsSold()` (orders not draft/cancelled/failed) and a copy of it inside `bestSellers()` | `unitsSoldOf()` reuses it; the copy in `bestSellers()` was removed |
| Button motion | `.sf-btn` transitions and `data-hover` (B36), motion profiles by package | a `button_hover` flag from the package (`animation.premium`) — not a store setting — and one CSS rule |
| Packages | `package_entitlements` with a migration per new feature (B32, B36 pattern) | `reviews.product`, `products.units_sold`: Business and Premium |

## 2. Fonts (owner decision 14)

- Offered: system-ui, Inter, Roboto, Poppins, Montserrat, DM Sans.
- Migration `2029_05_01_000001_sans_serif_fonts_only` rewrites drafts, live
  configurations and the publication history (a rollback never brings a serif
  font back). The validator refuses serif fonts (422).
- Urdu pages keep Noto Nastaliq Urdu: it is the Urdu script, not a Latin
  serif font. If the owner prefers a simpler Urdu face, that is a separate
  choice.

## 3. Reviews and ratings (owner decision 15)

Who may review: a signed-in customer who **bought** the product (an order that
was not a draft, cancelled or failed) and may order (blocked customers may
not). One review per customer and product; changing it sends it back to
moderation. Every review is therefore a verified purchase.

| Step | What happens |
|---|---|
| Customer writes | `POST /storefront/products/{slug}/reviews` — rating 1–5, optional title, text 10–2 000 characters; throttled; the name shown is "Ayesha K." (first name + initial) |
| Store moderates | Catalog → **Reviews**: waiting / published / rejected, publish, reject, reply, delete (`reviews.manage`) — or the setting "Publish reviews without checking" |
| Shoppers see | approved reviews only, newest first, with "Verified purchase" and the store's reply; the summary (average to one decimal, count, stars 5→1) |
| Rating | `products.review_count` and `rating_total` (integers), recounted on every change; cards show stars and count; product structured data gets `AggregateRating` |
| Privacy | data export lists the customer's reviews; erasure deletes them and recounts the products |

Data: `product_reviews` (store, product, customer, order, rating, title, body,
author name, status, verified, reply, moderated by/at), unique per product
and customer.

## 4. Units sold

Product pages show "120 sold" from the store's orders (not drafts, cancelled
or failed). It is counted **outside** the page cache, so a new order shows at
once. Settings: show it or not, and the minimum number before it is shown.

## 5. Premium button motion

`ThemeResolver` adds `motion.button_hover` = the package includes
`animation.premium`. The storefront sets `data-button-motion`, and storefront
action buttons (`.sf-btn`: add to cart, checkout, place order, sign in, …)
rise 2 px with a soft shadow on hover — mouse pointers only, off when the
store turned hover effects or motion off, and off for "reduce motion".

## 6. Settings

| Key | Default | Meaning |
|---|---|---|
| `reviews.enabled` | on | reviews on the storefront (when the package includes them) |
| `reviews.auto_approve` | off | publish without checking |
| `storefront.show_units_sold` | on | show the number sold (when the package includes it) |
| `storefront.units_sold_minimum` | 1 | the number from which it is shown |

## 7. Not built here

Review photos (Module 05 §27 "images where supported"), sorting and
filtering by rating (§18–19), review request emails after delivery (Module 15
§32), helpful votes, reviews from guests.
