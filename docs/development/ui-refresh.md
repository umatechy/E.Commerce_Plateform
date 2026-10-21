# UI refresh before launch (owner request, 2026-10-16)

The owner froze features after B58 and asked for the interface to be made
"best" before his own testing, in this order:

| Part | Screens | Status |
| --- | --- | --- |
| UI-1 First impression | Public home page, sign in, two-step code, create store, forgot/reset password, team and partner invitations; platform logo and name | ✅ |
| UI-2 Admin frame and dashboard | Sidebar with icons, header, search, store dashboard | ✅ |
| UI-3 Lists and product form | Orders, products, customers; add/edit product | ✅ |
| UI-4 Shop | Placeholders, cart badge, product page | ✅ |
| UI-5 Super Admin and finish | Figures, attention, empty and error states | ✅ |

## UI-1

- **Brand**: `platform.brand_name` (default "Umar Techy") and `platform.logo_url` (an https:// address or a
  site path of an SVG/PNG/JPG/WEBP picture) are platform settings (Super Admin → Platform settings),
  shared with every page as `brand`. Without a logo the built-in mark (`Components/Brand.tsx`) is drawn.
- **Icons**: `Components/ui/Icon.tsx`, a small set of line icons drawn in the code (no package).
- **Sign-in frame** (`Components/Auth/AuthShell.tsx`): brand and what the platform does beside a form card;
  on a phone the brand sits above. Show/hide password; a strength hint on new passwords (a help only — the
  server's rules decide); the two-step code as six boxes over one real input (typing, paste, the phone's
  code suggestion and screen readers all work; a pasted code is sent at once); recovery codes as fallback.
- **Create store**: one page in two parts, so server errors always sit next to their field.
- **Home page**: hero, features, three steps, packages and prices from the database
  (`Packages\Support\PublicPlans` — only built features are named, never AI or other unbuilt ones),
  partners, questions, footer. No invented figures or testimonials.
- Fixed on the way: the logo's gradient id was shared, so the second logo lost its colour when the first
  was hidden (phone).

## UI-2

- **Menu**: an icon for every entry (`NAV_ICONS` in `lib/adminNav.ts`); groups fold away and the choice is
  remembered on the device, but the group of the open page always stays open.
- **Go to… (Ctrl+K / ⌘K)**: `Components/CommandPalette.tsx` — searches the pages the menu offers this user and
  two quick actions (add a product, create an order) by name, purpose and group; arrows, Enter, Esc. It only
  moves between pages the menu shows; each page’s API still decides.
- **Header**: search button, storefront link with icon, account menu with initials.
- **StatCard** (every page): optional icon and a coloured trend arrow from the server’s percentage.
- **Dashboard**: greeting and the store’s name, View shop / Add product; the setup checklist as a progress ring
  with only the open steps (completed ones behind a button); four main figures with trends, four small
  ones for what needs doing; **revenue by day** (`/reports/sales`, financial permission only) as a bar chart
  over the whole period up to today, where a day without orders is a real zero, with a table for screen
  readers (`Components/ui/BarChart.tsx`); recent orders with the customer; the “Go to” list removed (the menu
  has the same links).
- Fixed on the way: on a phone the dashboard was wider than the screen (a grid column grew to its content).
  Team (465 px) and Inventory (391 px) are still wider than a phone; they are list pages (UI-3).

## UI-3

- **Every list** (`Components/ui/DataTable.tsx`, 44 pages): softer frame, roomier rows, a hover on every row; a
  click on a link, button or field inside a clickable row does its own thing only.
- **Row menu** (`Components/ui/RowMenu.tsx`): less used and risky actions behind “⋯” (“Actions for <name>”), with
  keyboard support — a delete is never one stray click.
- **Products**: first picture (or a placeholder), **stock available** (on hand minus reserved, all warehouses and
  variants; out of stock in red, low in amber), Edit/Duplicate/Delete in the row menu. The product API list now
  returns `thumbnail_url` and `stock_available` (signed arithmetic, so an oversold product never errors).
- **Orders**: one-click status tabs (All, To confirm, Confirmed, Processing, Shipped, Delivered, Cancelled); the
  whole row opens the order. **Customers**: the whole row opens the customer.
- **Add/edit product**: pictures by drag and drop or click, the first marked “Main”; the save bar stays in view
  at the bottom, with an unsaved-changes mark.
- **Team**: the shared look; initials; the member table scrolls inside its box on a phone.
- Fixed on the way: on a phone the product list hid the product name (the select box was the only column
  kept); the team page was wider than the screen.

## UI-4

- **Header**: Account and Cart with icons (words from the small breakpoint up; the links keep their names for
  screen readers) and a **cart badge** with the number of items. The count is taken from every cart the API
  sends back (`Storefront/api.ts`), never fetched for the badge alone, because reading a cart creates one for a
  visitor who has none; other tabs follow through the storage event.
- **No picture yet**: an image icon on the shop’s own surface colours instead of bare words, on cards and the
  product page.
- **Product page on a phone**: once Add to cart has scrolled out of view, a bar at the bottom offers it again
  with the name and price, above the app’s tab bar when that is shown. It exists only while the main button is
  out of view.
- The sideways-scrolling category row on a phone was kept (it is the theme’s design, tested); the demo store
  still has no product pictures — they are the owner’s to add.

## UI-5

- **Platform overview**: “Needs attention” at the top (overdue backup, failing scheduled backups, failing
  infrastructure checks, failed payments), each with a link; “All clear” when there is nothing. Figures with
  icons and grouped digits; sums over several currencies stay plain numbers (a currency sign would be wrong).
- **Every page**: cards with the shared frame; error panels with a warning icon; empty panels with an icon (an
  optional `icon` per page).

# Second round (owner request, 2026-10-17)

After testing, the owner asked for two things:

- a fix: after a new store turned on two-step sign-in, the Security page stayed open instead of going to the dashboard;
- a professional, modern admin on shared Tailwind components, with hover effects and light motion, on every page.

"Theme 3" was confirmed as **three colour themes the user picks**: Indigo, Emerald and Slate. It does not mean light and dark modes.

| Part | What | Commit |
| --- | --- | --- |
| UI-6 First impression | Guided two-step set-up ending on the dashboard; sign-up says it comes next | 08e452c, 4d70b3e |
| UI-7 Design system | Three themes, buttons, cards, tables, dialogs, toasts, fields, motion | c7c5273 |
| UI-8 Frame and dashboard | Current menu entry marked, taller header, welcome banner, setup steps | c7c5273, e4e7d78 |
| UI-9 Settings and older pages | Grouped settings (store and platform); older pages moved onto the components | e4e7d78 |
| UI-10 Every page | 48 store and 19 platform pages checked on desktop and phone; fixes | 4d70b3e |

## UI-6

- **Security** (`Pages/Security/Index.tsx`): turning two-step sign-in on takes three steps, with a stepper:
  1. confirm the password;
  2. scan the QR code and type the code into six boxes (a pasted code is sent at once);
  3. save the recovery codes, with a "Copy codes" button.
- An owner who had to turn it on then gets **"I have saved them — go to my dashboard"**.
- When two-step sign-in is already on, the management form stays closed behind "Manage". It says it is not a sign-in: its password and code fields had looked like a second login.
- The session was already verified on confirm (`SecuritySession::markMfaVerified`), so the dashboard opens without signing in again.

## UI-7

- **Themes** (`lib/adminTheme.ts`, `resources/css/app.css`):
  - Tailwind's `brand-*` colour reads the CSS variables `--brand-50…950`, stored as RGB channels so opacity modifiers work.
  - `data-admin-theme` on `<html>` switches them. It is applied in `app.tsx` before the first render.
  - The choice is a per-browser preference in localStorage (wrapped; falls back to Indigo). Pick it in the account menu under "Theme colour".
  - Emerald's shades from 500 up are one step darker, so white text on `brand-600` meets WCAG AA.
  - The storefront and the public home page keep their own colours.
- **Components** (`Components/ui/*`):
  - Buttons: lift on hover, press on click, spinner while busy, new `soft` and `outline` variants, new `lg` size.
  - Cards, tables, dialogs and toasts are rounder. Dialogs have a blurred backdrop and scale in (drawers slide in). Toasts have icons.
  - Fields are 40px tall with a theme-coloured focus. The search field has an icon.
- **Motion**: `fade-in`, `fade-in-up`, `scale-in` and `slide-in-right`, used only with `motion-safe:`.
  - The fill mode is **`backwards`, never `both`**. A transform left on the page wrapper made it the containing block of fixed and absolute children, which broke dialogs and sticky bars and let hidden labels widen phones (UI-10).

## UI-8

- **Frame**:
  - The current menu entry gets a theme-coloured bar.
  - "Locked" and "Upgrade" show as pills.
  - The header is 64px with a wider "Go to…" box.
  - The account menu shows the name and email, the theme picker, Security and Log out.
  - Each page eases in.
- **Dashboard**:
  - A welcome banner in the theme gradient, with date, greeting, store name and View shop / New order / Add product.
  - Setup steps show a "Required" pill and a theme-coloured "Open".
  - The figures lift on hover.

## UI-9

- **Settings editor** (`Components/SettingsEditor.tsx`):
  - Settings are grouped into cards, with a side menu on large screens. A setting's group is `info.group`, else the first part of its key.
  - The current value shows as a chip: On/Off coloured, "Not set" muted. History sits behind a clock icon.
  - Store settings have seven named groups; platform settings group by key prefix.
- **Older pages**: these used `gray-*` and plain boxes and were moved onto the components' look by exact class strings, then finished by hand:
  - platform backups, support inbox and tickets, invitations, team, health list;
  - the empty, loading and error states.

## UI-10

Every admin page was opened at 1440 and 390 pixels:

- 48 store pages, signed in as an owner with demo data;
- 19 platform pages, signed in as platform staff.

Fixes:

- Phones scrolled sideways on payments, inventory, audit log and resellers (5–124px). The cause was screen-reader-only labels inside tables escaping their scroll box. Every table scroll box is now positioned, and the animation fix above removed the root cause.
- Inventory row actions fold into a "⋯" menu on phones.
- The product save bar meets the page edges at every width.

Result: no script errors, no server errors, no sideways scrolling.

# Third round: fifteen improvements (owner request, 2026-10-17/18)

The owner lifted the feature freeze for these fifteen suggestions and asked for all of them in the proposed order.

| # | What | Commit |
| --- | --- | --- |
| 1 | E2E sweep of every store admin page, desktop and phone: no script or server error, no sideways scrolling (`tests/e2e/pages.spec.ts`) | bd7cee8 |
| 2 | ESLint keeps the admin on the design system: no `gray-*`, fixed `indigo-*`, bare `rounded border` or hand-made dark buttons | e6788d8 |
| 3 | Unsaved changes are never lost: menu links ask "Leave without saving?"; dialogs ask before Escape, the backdrop or ✕ discard input (`lib/leaveGuard`) | 56393ca |
| 5 | Ctrl+K finds orders, products and customers (only the lists the user may open) | eecf6d9 |
| 7 | Order page "next step" bar; WhatsApp click-to-chat (wa.me, no provider) and call; printable packing slip `/orders/{id}/print`; same actions in each order row | f667ac0 |
| 9 | Quick start for a new store: contact, first product with stock, open the store | b0c2573 |
| 6 | Header bell, "Needs attention" (`GET /api/v1/attention`, per permission) | b043b5a |
| 11 | Admin in Urdu, right to left, Nastaliq (`lib/adminI18n`, `lib/adminUr`); untranslated text stays English, isolated | f5f3c50 |
| 10 | Phone tab bar and an installable admin app (`/admin.webmanifest`, no service worker) | 1321f51 |
| 4 | Theme colour, language and mode kept with the account (`PUT /api/v1/auth/preferences`) | e311253 |
| 8 | Price and stock changed in the product list; saved list views (orders, products, customers) | ecd9eb2 |
| 12 | Dark mode: Light / Dark / System; slate turned around through CSS variables | 09d3ab9 |
| 13 | Dashboard suggestions from the store's own figures (`GET /api/v1/insights`) | 352d1e2 |
| 14 | First-year upgrades measured on the one-time prices | 8090ed2 |
| 15 | Browser errors reported to Super Admin → Monitoring; a broken page keeps the frame (error boundary) | c2f186d |

## UI-26 — an easier admin: fewer entries, fewer clicks, clear sections (2026-10-21)

Asked by the owner after testing: users got lost, some screens were not organised,
and it was not always clear what to do on a page. Plan agreed in chat (stages A–D);
this round does A1, A3, B1, B4-in-part and the shared look.

- **Sidebar: 7 sections instead of 44 entries** (`lib/adminNav.ts`). Orders (blue),
  Products (emerald), Customers (violet), Marketing (pink), Online store (amber),
  Reports (cyan), Settings (slate). Only the section of the open page shows its pages;
  another opens with its arrow. Settings is one entry that opens a page listing every
  setting in four groups (`Pages/Settings/Hub.tsx`); the old general settings page is
  now `/settings/general` ("Store details"). Security moved to the account menu and the
  Settings page (the sidebar shows it only while two-step sign-in is owed).
- **Merged and renamed:** Shipping setup → Settings › Delivery; Payments setup →
  Payment methods; Billing → Package & billing; Inventory → Products › Stock;
  Warehouses → Stock locations; Attributes → Product options; Promotions → Discounts;
  Segments → Customers › Customer groups; Messages → Customer messages; Support under
  Customers. No page is listed twice (tested).
- **Every page of a section shows the others as tabs** under its heading
  (`SectionTabs`, from `AdminPage`), so related pages are one click apart.
- **Section colours** (`--tone`, `.tone-*` in `app.css`, light and dark): the page icon,
  the section tabs and every card heading (a coloured strip and wash) use the colour of
  the section, so it is clear where one task ends and the next begins.
- **Shared controls:** field labels in the theme blue and semibold, taller rounded
  inputs, gradient primary and danger buttons at the same height as inputs. Tab rows no
  longer show a stray vertical scrollbar.
- **Add product on one page:** 1. what you sell, 2. price, 3. stock ("How many do you
  have?", saved in the default warehouse — `lib/stock.ts`, shared with the quick start),
  4. pictures, then "More options" folded. New products are Active. "This product comes in
  sizes or colours" replaces the type list for the common case. After creating, the
  product page says what to do next (add sizes, add another, see it in the store, create
  an order). This removes the "out of stock" surprise of a product created without stock.

Still to do from the plan: orders worked by stage (B2 — the status chips already exist),
guided setup centre (B3), stock shown while picking a product for an order (B4), the page
pattern and empty states on the remaining pages (C1–C4), lighter Reports and Theme (C5),
and testing with real users (D1).

## Notes for later work

- **Urdu:** the frame, shared components, dashboard, quick start, orders, security, bell and search are translated. The bodies of other pages stay English until their words are added to `lib/adminUr.ts`; `resources/js/test/adminI18n.test.tsx` lists any `t()` text without Urdu.
- **WhatsApp:** messages open in the seller's own WhatsApp. Sending from the platform (the Meta adapter) still needs the owner's provider account; none is invented.
- **Upgrade rule (14):** the proration rule is used, not the full difference of the one-time prices. Changing it is an owner decision (see `docs/architecture/pricing-one-time-renewal.md`).
- **Dark mode:** it relies on the design-system classes. A page that sets its own colours (storefront theme previews) keeps them.
