# B36 — Inspection findings (Modules 17 and 18)

Owner decision 2026-10-04 (in the session): "theme wali jo tajweez aap ny di hy
ok hy; g15 sy pehly module 17 and 18 py working krni hy" — themes by package:
Basic 2, Business 3, Premium all + premium layouts + animation + section
reordering; Modules 17 and 18 before G15.

## 1. Starting point (before B36)

One system theme; tokens (colours, one font that was never actually loaded,
radius), branding, 8 section types of which 4 rendered, draft / preview /
publish / rollback, custom CSS. No layout choices, no motion. Premium flags in
the package (AI, loyalty, PWA, advanced reports and marketing) have no code —
reported to the owner; not part of this phase.

## 2. Spec mapping (main acceptance items)

| Spec | Status |
|---|---|
| M17 #1 multiple themes coexist; #2 a store selects one | ✅ library of 5, selection API and screen |
| #3 branding, #4 tokens, #5 colours, #6 typography | ✅ (+ heading font, shadow, density; contrast warning) |
| #7 layout, #8 header/navigation/footer | ✅ header ×4, hero ×4, cards ×4, columns, width, sticky, footer ×2 |
| #9 homepage sections, #10 product presentation | ✅ 15 section types; card styles, second image, add to cart |
| #11 responsive | ✅ checked at 390 px for every header style |
| #12 RTL/LTR | ❌ G11 |
| #13 accessibility | ✅ text alternatives, focus, live regions, reduced motion, contrast warning |
| #14 dark mode | ❌ not built |
| #15 animation integration | ✅ theme selects the motion profile |
| #16 preview, #17 publish, #20 rollback | ✅ (B15/B32, now with package checks) |
| #18 scheduling, #19 versioning | ❌ scheduling; versioning is per publication, not per theme version |
| #21 custom CSS controlled | ✅ (B15) |
| #22 tenant isolation, #26 tenant-safe cache | ✅ tests |
| #23 package entitlements | ✅ 7 features, server-checked, fallback on downgrade |
| #24 API and events, #25 audit | ✅ |
| M18 #1–2 tokens and profiles; #3 store configuration | ✅ |
| #5 page transitions, #6 component transitions, #7 product interactions, #8 cart feedback | ✅ (page entry fade; hover, press, card motion; add-to-cart after the server) |
| #9 wishlist feedback | ⚠️ on the product page only (no wishlist on cards) |
| #10 loading/skeleton, #11 modal/drawer, #12 toast | ⚠️ storefront has few of these; buttons show busy states |
| #13 scroll reveal | ✅ capped stagger |
| #14 gestures | ❌ (no carousel yet) |
| #15 reduced motion, #16 device adaptation | ✅ reduced motion; hover only on fine pointers |
| #19 scheduling | ❌ |

## 3. Decisions taken (reported, reversible)

1. **Tier of each step** between Basic and Premium (the owner set the two
   ends): Business gets the Modern theme, advanced sections and standard
   motion; layouts, Premium/Playful motion and reordering are Premium.
   Platform staff can change any feature per package.
2. **Downgrade keeps the configuration and renders within the package** —
   Module 17 says to preserve theme configuration across package changes; the
   store sees its own settings again after upgrading.
3. **Best sellers stay hidden until there are sales** instead of showing other
   products under that name.
4. **Classic keeps the key `default`** so every existing store keeps its look.
5. **Fonts are self-hosted** (`@fontsource`), not a font CDN.
6. **Choosing a theme resets colours, fonts, layout and motion** to the
   theme's own; branding and sections stay.

## 4. Findings during the phase

| # | Finding | Action |
|---|---|---|
| F1 | The font list existed since B15 but no font file was ever loaded (the browser fell back to system fonts) | Fixed: self-hosted fonts loaded per theme |
| F2 | A package change did not clear the storefront cache | Fixed: `Subscription` observed by the storefront cache |
| F3 | Two existing tests assumed no theme rows exist (now registered by migration) | Updated: they remove/firstOrCreate the row themselves |
| F4 | The local environment had no `public/storage` link, so uploaded product images answered 403 | Local setup: `php artisan storage:link` (deployment step, not code) |
| F5 | `@inertiajs/react` test mock had no `Head` | Added to the mock |

## 5. Not built (stated, not hidden)

See `docs/architecture/b36-themes-and-motion.md` §10: dark mode, RTL, logo
upload with SVG sanitizing, scheduling, per-version theme records,
import/export, custom components, mega menu, quick view, ratings, product
detail settings, carousel and gestures, motion analytics, split permissions.

## 6. Owner decisions that would change behaviour

None blocks. The owner may later want: which features Business gets; whether
Basic may reorder sections; a dark mode for Premium.
