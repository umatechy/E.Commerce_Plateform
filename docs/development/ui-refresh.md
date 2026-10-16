# UI refresh before launch (owner request, 2026-10-16)

The owner froze features after B58 and asked for the interface to be made
"best" before his own testing, in this order:

| Part | Screens | Status |
| --- | --- | --- |
| UI-1 First impression | Public home page, sign in, two-step code, create store, forgot/reset password, team and partner invitations; platform logo and name | ✅ |
| UI-2 Admin frame and dashboard | Sidebar with icons, header, search, store dashboard | planned |
| UI-3 Lists and product form | Orders, products, customers; add/edit product | planned |
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
