============================================================
PHASE B36 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B36 — Theme library, layouts, home page sections and motion
(Module 17 Theme, Branding & Design System; Module 18 Animation &
Interaction; Module 04 §32–33). Gap G17 core; Module 17 depth.

Starting point:
v1.1 at 6f17a6f (B35, CI green). One theme, four rendered sections, no
layout choices, no motion; the store looked the same on every package.

Owner decision (2026-10-04):
Basic 2 themes, Business 3, Premium every theme + premium layouts +
animation + section reordering; Modules 17/18 before G15.

Implementation Summary:

1. Theme library — ThemeCatalog: Classic (key `default`), Minimal
   (Basic), Modern (Business), Boutique, Bold (Premium). Migration
   2028_09_01_000001 registers them and adds 7 package features;
   PackageSeeder too. GET /store/themes, POST /store/theme/select.
2. Configuration — theme, tokens (11 colours, body/heading font,
   radius, shadow, density), layout (header, hero, cards, columns,
   width, sticky, footer), motion (profile, intensity, reveal, hover);
   strict whitelist validation; inheritance platform → theme → store.
3. Package rules — ThemeEntitlements refuses what the package lacks on
   save, select, publish and rollback (403 with a list), keeps a fixed
   section order without `homepage.reorder`; on a downgrade the stored
   configuration is kept and the storefront falls back within the
   package. Subscription changes clear the storefront cache.
4. Sections — best sellers (by units sold; hidden without sales), on
   sale, brands, testimonials, FAQ, text block, trust badges; button
   label on hero and banner.
5. Storefront — CSS variables and data attributes from the resolved
   theme; 4 header styles, sticky header, widths, 2 footers; 4 card
   styles with second image and add to cart; 4 hero styles; fonts
   self-hosted (8 families).
6. Motion — 5 profiles × 3 intensities; hover, press, page entry,
   reveal on scroll (capped stagger), add-to-cart confirmation after the
   server; prefers-reduced-motion always honoured; nothing hidden
   without JavaScript.
7. Admin — Theme page: Themes, Colours and type (contrast warning),
   Layout, Animation, Branding, Home page (item lists, reorder only when
   included), Custom CSS, History; locked choices shown with the package.
8. Demo store — demo:store draws two pictures per product and publishes
   a theme (default Boutique) with a demo home page;
   --refresh-look=<slug> for an existing demo store.

Tests (2026-10-04):
PHP: 1106 passed (1098 before; ThemeLibraryTest 8; demo store test
extended; ThemeServiceTest and SuperAdminThemeManagementTest adjusted
for the registered themes).
PHPStan: no errors.
Vitest: 173 passed (163 before; theme.test.tsx 10).
ESLint, TypeScript: clean. Production build: passes.
composer audit: no advisories. npm audit --omit=dev: 0.

Browser verification — EXECUTED (Chromium via Playwright, local server,
MySQL 8.0, MFA on, production build): 6 of 6 checks.
1. New store on the Basic trial: 5 themes, 3 locked; premium layout,
   premium motion and Bold refused by the server (403).
2. On Premium: Boutique chosen, published; storefront has the centred
   header, premium motion, Playfair Display headings loaded from the
   site, no third-party font request.
3. Add to cart from a product card; "Added ✓"; the cart has it.
4. Long demo store: 23 elements reveal on scroll and none stays hidden
   after scrolling; with reduced motion none is hidden and transitions
   are 1 ms.
5. Back to Basic: storefront shows Classic, configuration kept,
   publishing Boutique again refused (403).
6. 390 px phone: no sideways scrolling with Bold, Minimal, Classic,
   Boutique.
Console errors: only the four deliberate 403s. Screenshots of all five
themes were reviewed.

NOT EXECUTED — ENVIRONMENT LIMITATION:
- Screen readers; browsers other than Chromium; real low-end devices.

Known limitations:
Dark mode, RTL/Urdu (G11), logo upload, scheduling, per-version theme
records, import/export, quick view, ratings, carousel/gestures — see
docs/development/b36-inspection-findings.md §5.
Local setup note: `php artisan storage:link` is needed for uploaded
images to show (also a deployment step).

Next:
G15 (catalog depth). G3 and G9–G10 wait for the owner's rules and
credentials.
