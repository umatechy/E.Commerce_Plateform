# UI refresh before launch (owner request, 2026-10-16)

The owner froze features after B58 and asked for the interface to be made
"best" before his own testing, in this order:

| Part | Screens | Status |
| --- | --- | --- |
| UI-1 First impression | Public home page, sign in, two-step code, create store, forgot/reset password, team and partner invitations; platform logo and name | ✅ |
| UI-2 Admin frame and dashboard | Sidebar with icons, header, search, store dashboard | ✅ |
| UI-3 Lists and product form | Orders, products, customers; add/edit product | ✅ |
| UI-4 Shop | Placeholders, mobile menu, cart badge, product page | planned |
| UI-5 Super Admin and finish | Figures, alerts, charts; empty and loading states | planned |

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
