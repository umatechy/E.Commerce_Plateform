============================================================
PHASE B31 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B31 — Admin UI (gap G6; "Admin Capabilities" and "Admin UI
Requirements" of Modules 06–17, 19, 21–24, 29–33; Module 22 §10;
Module 30 §7; Module 33 §64–66)

Starting point:
v1.1 at 23c9858 (CI green: run 36836997686). The backend had 28
domains behind /api/v1. The admin had 11 pages: a link-list dashboard,
Orders, Inventory, Billing, Store health, Team, Security, Support (2),
and for platform staff Support and Backups. Everything else worked
only through the API.

Implementation Summary:

1. Admin shell
- Sidebar navigation in sections (Sell, Catalog, Marketing, Storefront,
  Insights, Store), collapsible on desktop, a drawer on phones; top bar
  with the store (and a switcher for users of several stores), a link
  to the storefront and the account menu; breadcrumbs; one page
  heading per page.
- The menu shows a role only the pages it may open. Platform pages are
  a separate, marked section for platform staff only.
- The two-step sign-in gate of 23c9858 is kept: an Owner without it
  sees every entry closed except Security, with the reason.

2. Design system (resources/js/Components/ui)
Buttons, form fields, dialog, confirmation dialog, drawer, table with
pagination, tabs, cards, figures, badges, toasts, load/empty/error
states, package notice, usage meter. Tailwind only; no library added.

3. One API layer
Every call has a timeout, safe error wording per status, and step-up:
when the server asks for the password again, a dialog asks, and the
same request is repeated once. No page can stay on "Loading…".

4. Store Admin pages (38 page routes in total with Super Admin)
New: Dashboard (real figures, setup checklist, launch, health, usage),
Products (list, search, filter, create, edit, variants, images),
Categories, Brands, Attributes, Warehouses, Order detail, Create
order, Payments, Shipments, Shipping setup, Customers (figures),
Promotions and coupons, Campaigns, Segments, Messages and templates,
Reports (9 reports, CSV export), Pages, Redirects, SEO, Theme, Domains,
Roles, Backups, Settings, Audit log, Developer (applications, API keys,
webhooks).
Rebuilt: Orders, Inventory, Billing, Store health. Adjusted: Team.
Kept: Security, Support.

5. Super Admin pages
New: Platform overview, Stores, Store detail, Users, Packages,
Platform billing (overview, invoices, prices), Monitoring (system,
store health, failures), Platform audit log, Themes/domains/developer
applications, Platform settings. Kept: Backups, Support.

6. Backend (small, additive)
- AdminShellProps: display props for the shell.
- A ULID in a URL resolves by public_id (HasPublicId); packages
  resolve by code.
- PackageSeeder: `products.basic` for all tiers.
- List filters (products, orders, inventory, payments, shipments).
- Resource fields the pages need; GET /shipping/rates, GET /permissions.
Details and reasons: docs/development/b31-inspection-findings.md.

Defects found and fixed:
1. The API returned public ids that its routes did not accept: order
   "Cancel" and stock "Adjust" answered 404 from any screen.
2. `products.basic` was required but never seeded: no real store could
   create a product. Only tests could.
3. A package could not be addressed by what the API returns for it.
4. Stock kept per variant showed no product name.
5. A URL key such as "12abc" was read as row 12.

Reused (not duplicated):
Every controller, policy, service, state machine and Form Request of
B1–B30; the step-up and MFA of B29; the typed settings of B17; the
audit trail of B22; StoreClock (B28); the backup helpers and the
Backups page of B30; the Team and Support components; lib/datetime,
lib/money, lib/backups, lib/support, lib/team.

Tests (2026-10-01):
PHP: 1027 passed, 0 failed (baseline 1011; 16 added in
tests/Feature/Admin/AdminUiTest.php; one route-inventory test updated
for the new Backups page route).
PHPStan: no errors.
Vitest: 126 passed (46 before; 80 added): navigation and access, API
layer and step-up, helpers (pagination shapes, URL state, money entry,
store-local dates, catalog, orders), the UI components, and the shell
with pages (MFA gate, store switch, step-up dialog, product list
filter/pagination/empty/error/retry/timeout/delete, server validation
and single submit, dashboard, promotion dates).
ESLint: clean. TypeScript: clean. Production build: passes.
composer audit and npm audit (shipped packages): no advisories.

Browser verification — EXECUTED (Chromium via Playwright, local server,
MySQL 8.0.40, MFA and step-up ON, production build):
38 of 38 checks passed.
- Owner journey (28): register; closed menu and API 403 without MFA;
  enrol; menu opens without reload; dashboard; sidebar and
  breadcrumbs; product create with server validation; edit, variant,
  exact price; categories, brands; stock record, opening stock,
  adjustment, history; admin order with server total; order cancel by
  public id; store launch; a guest's storefront order (cash on
  delivery); that order's customer and address; shipment through to
  delivered with an invalid step refused; cash recorded; refund with an
  over-refund refused; 20 further pages load without error; timezone
  change and its history; billing; theme draft, publish, server
  refusal of a bad colour; restore request with typed backup id;
  Super Admin pages and API refused (403); not-found states; injected
  500 shown safely and recovered by "Try again"; an unanswered request
  ending in an error after 15 s; phone (390 px) and tablet (820 px)
  without sideways scroll, drawer menu, dialog; keyboard (skip link,
  focus trap, Escape, focus return); sign out and in with a code;
  step-up with a wrong password, a cancel, then success.
- Staff role (4): password-only sign-in; menu limited to the role;
  403 from the API for five areas outside it; no create/delete/cost
  price, and a hand-made update refused.
- Platform staff (6): sign-in with code; platform-only menu; 17
  platform views load; store detail; a setting change behind step-up;
  audit entry and integrity check.
The step-up window (15 minutes) was made to look expired by ageing the
test session's timestamp, instead of waiting.

NOT EXECUTED — ENVIRONMENT LIMITATION:
- Real payment gateway, courier, SMS/WhatsApp: none is connected. The
  pages show the three built-in payment methods and carriers and say
  which are test-only.
- Real email delivery: the mail driver is `log`. Campaign sending and
  invitations were not followed to an inbox.
- A custom domain's DNS verification: needs a real domain. The page
  flow up to the DNS record and the server's "not found yet" answer
  rests on the B14 tests.
- A queue worker: the local queue is `sync`. A slow CSV export or
  backup in the background was not observed; the export button's
  waiting state is unit-level only.
- Product image upload was not exercised in the browser run.
- Screen readers (NVDA, VoiceOver) were not run. Accessibility was
  checked by roles, labels, focus and keyboard in tests and in the
  browser, not with assistive technology.
- Browsers other than Chromium.
- Colour contrast was chosen from Tailwind's accessible pairs; no
  automated contrast audit was run.

Known limitations (backend gaps the UI states rather than hides):
1. Customers: no list or detail (gap G7). The privacy export/erase API
   has no screen until then.
2. An order created by staff has no payment and cannot be shipped.
3. A variant without its own price cannot be ordered.
4. No edit/delete for attributes, shipping zones and methods, segments;
   no delete for warehouses and promotions; package entitlements are
   read-only; platform settings have no history.
5. Promotion product targets chosen earlier show as "Product #n".
6. Dashboard sums ignore currency (shown with a note).
7. Security, Support and the platform Backups page keep their earlier
   headings (no breadcrumbs).
8. The browser checks are scripts outside the repository; they are not
   part of CI (gap G13 asks for Playwright journeys in CI).
Full list: docs/development/b31-inspection-findings.md.

Requirements closed:
Gap G6. Finding 3 of the gap matrix ("most modules exist only as
APIs"). The "Admin Capabilities" of Modules 06–09, 12–17, 19, 21–24,
29–33 as far as the API supports them.

Next:
G7 (Module 10: customer list, detail, groups, notes) — the largest hole
left in the admin. Then G8 (returns) and G15 (catalog depth). G3 (tax)
and G9–G10 (providers) still wait for the owner's rules and credentials.
