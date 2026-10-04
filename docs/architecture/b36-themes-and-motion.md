# B36 — Theme library, layouts, home page sections and motion (Modules 17 and 18)

Owner decision 2026-10-04: build Modules 17 and 18 before G15. Themes by
package: Basic 2 themes, Business 3, Premium every theme plus premium layouts,
animation and section reordering.

Specs read in full before building: Module 17 (Theme, Branding & Design
System) and Module 18 (Animation & Interaction); Module 04 §32–33 for the
package steps.

## 1. One engine, configuration only

Module 17 §1: "one core storefront engine + one config-driven design system +
ready-made themes + tenant brand configuration + package entitlements". No
theme has code of its own. A theme is a definition in
`App\Domain\Theme\Support\ThemeCatalog`:

| Key | Name | Tier | Look |
|---|---|---|---|
| `default` | Classic | Basic | the B15 theme, kept under its key so existing stores do not change |
| `minimal` | Minimal | Basic | black and white, square corners, DM Sans, slim header |
| `modern` | Modern | Business | indigo, rounded, Poppins headings, sticky header, standard motion |
| `boutique` | Boutique | Premium | warm, Playfair Display headings, centred logo, split hero, premium motion |
| `bold` | Bold | Premium | rose, Montserrat, coloured header bar, full-width hero, overlay cards, playful motion |

Each definition has tokens (11 colours, body and heading font, radius,
shadow, density), a layout and a motion profile, plus version, description
and a performance profile (LIGHT / STANDARD / RICH, §35). The `themes` table
has a row per key (migration `2028_09_01_000001`, `ThemeSeeder`); that row is
what a store references.

## 2. Configuration and inheritance (§39)

A store's draft and published configuration (`store_themes`) now may hold:

```
theme, tokens{…}, layout{header_style, container, product_card, grid_columns,
hero_style, sticky_header, footer_style}, motion{profile, intensity,
reveal_on_scroll, hover_effects}, branding{…}, sections[…]
```

`ThemeResolver::present()` computes what is rendered, deterministically:
Classic (platform defaults) → selected theme → the store's own values. The
stored configuration is never rewritten by the resolver.

Allowed values are fixed in `ThemeOptions`; `ThemeConfigValidator` rejects any
unknown key or value (fonts only from the list, colours only hex, URLs only
https, section items with fixed fields and lengths, at most 30 sections).

## 3. Package rules (§41, Module 18 §40)

New package features (migration and `PackageSeeder`; platform staff can change
them on the Packages page):

| Feature | Basic | Business | Premium | Gives |
|---|---|---|---|---|
| `themes.business` | – | ✓ | ✓ | Modern |
| `themes.premium` | – | – | ✓ | Boutique, Bold |
| `layout.advanced` | – | – | ✓ | centred / coloured-bar header, split / full-width hero, elevated / overlay cards |
| `homepage.advanced_sections` | – | ✓ | ✓ | trust badges, best sellers, on sale, brands, testimonials, FAQ, text block |
| `homepage.reorder` | – | – | ✓ | free order of sections |
| `animation.advanced` | – | ✓ | ✓ | Standard profile, reveal on scroll |
| `animation.premium` | – | – | ✓ | Premium and Playful profiles, high intensity |

`ThemeEntitlements`:
- `violations()` — saving a draft, choosing a theme, publishing and rolling
  back are refused (403 `feature_not_entitled`, with a list) when the
  configuration uses something the package lacks. Publishing checks again,
  because the package may have changed since the draft was saved.
- `normalizeOrder()` — without `homepage.reorder` the server puts sections in
  the fixed order (`ThemeOptions::CANONICAL_ORDER`).
- On a downgrade nothing stored is changed (Module 17 §2.17). The resolver
  shows the nearest included presentation: a theme outside the package falls
  back to Classic, premium layout values to Classic's, motion steps down
  (premium/playful → standard → minimal), advanced sections are not
  rendered, the order becomes the fixed one. Upgrading again restores it.
- A subscription change now clears that store's storefront cache
  (`Subscription` added to `StorefrontCacheObserver`).

## 4. Theme selection (§18)

`GET /api/v1/store/themes` lists the library with `included`, `published`,
`in_draft` and each theme's tokens/layout/motion (for the cards).
`POST /api/v1/store/theme/select {theme}` puts the theme in the draft with its
own colours, fonts, layout and motion; branding and sections stay. Choosing
the current theme again resets it to its defaults. Publish makes it live and
sets `store_themes.theme_id`. Audit entries: `theme.selected`,
`theme.draft_updated`, `theme.published`, `theme.rolled_back`; outbox event
`theme.selected` (in addition to the B15 ones).

## 5. Home page sections (§25)

New section types: `best_sellers` (units sold on non-cancelled orders; hidden
until there are sales — never filled with other products), `sale_products`,
`featured_brands`, `testimonials` (≤ 6), `faq` (≤ 12), `rich_text`,
`trust_badges` (≤ 4, icons from a fixed set drawn by the storefront). Hero and
banner gain a button label. All text is stored and rendered as text.

## 6. Storefront rendering (§51)

- `StorefrontExperience` sends `theme {key, name, tokens, layout, motion,
  custom_css}` in the shell and the resolved sections to the home page.
- `Storefront/theme.ts` turns it into CSS variables (`--sf-*` colours, radius,
  shadow, density, fonts, motion durations/easing/distance/lift/scale) and
  data attributes (`data-theme`, `data-motion`, `data-hover`,
  `data-page-motion`). Components only read those.
- `StoreLayout`: four header styles, sticky header, page width, simple or
  columns footer; category menu scrolls sideways on phones.
- `ProductCard`: four styles, second image on hover, "Add to cart" on the
  card for products without variants ("Choose options" otherwise, "Sold out"
  disabled); the name, price, sale and stock state stay visible in every
  style.
- `Home`: hero styles and the new sections.
- Fonts (§8): eight families from npm `@fontsource` packages, bundled by Vite,
  Latin subset, loaded only when the theme uses them. No request goes to a
  font service.

## 7. Motion (Module 18)

- Tokens per profile in `Storefront/theme.ts` (§5–8): NONE (no motion),
  MINIMAL (fades, small zoom), STANDARD, PREMIUM (slower, emphasised easing),
  PLAYFUL (small overshoot, no loops); intensity scales distance, lift and
  zoom (§28).
- CSS in `resources/css/app.css`: hover lift/zoom/second image on fine
  pointers only (§13), press feedback (§14), page entry after render (§11),
  add-to-cart pop only after the server answered (§16), reveal on scroll
  (§22).
- `Reveal`: IntersectionObserver; hides an element only after the script ran,
  so nothing is hidden without JavaScript (§22 rule 1); stagger capped at 8
  steps / 480 ms (§23); cleans up on unmount (§34).
- Reduced motion (§27): `prefers-reduced-motion` turns transitions and
  animations to 1 ms and never hides content; state changes keep their text
  ("Added ✓", status messages).
- Transform and opacity only (§30); no flashing or loops (§43).

## 8. Admin (§38, §50; Module 18 §36, §53)

Theme page tabs: Themes (library cards drawn in each theme's colours), Colours
and type (theme values shown as placeholders, contrast warning below 4.5:1 —
§5), Layout, Animation, Branding, Home page (section editor with item lists,
move buttons only with `homepage.reorder`), Custom CSS, History. What the
package lacks is visible but disabled, with a package notice.

## 9. Demo store

`php artisan demo:store` now gives every product two pictures (drawn with GD:
gradient and a shape for the product type, no text, alt "Demo image of …")
and publishes a theme (`--theme`, default Boutique) with a demo home page
whose texts say it is sample data. `--refresh-look=<demo slug>` does the same
for an existing demo store.

## 10. Not built (stated, not hidden)

Dark mode (§32); RTL and Urdu fonts (§9, gap G11); logo/favicon upload with
SVG sanitization (§7 — logos are still https addresses); theme and motion
scheduling (Module 17 §39, Module 18 §39); immutable records per theme version
(§17 — the store follows the theme key; definitions change only with a
release); theme import/export (§43); custom components (§37); mega menu,
header wishlist/cart count; quick view and ratings on cards (no reviews
module yet); product detail presentation settings (§27); carousel and
gestures (Module 18 §24–25); animation analytics events (Module 18 §46);
separate permissions per theme action (§49 — `theme.manage` and
`theme.publish` cover them).
