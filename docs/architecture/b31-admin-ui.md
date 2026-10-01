# B31 — Admin UI (gap G6)

Phase B31 gives the existing backend a complete admin: a Store Admin for
a store's own data and a separate Umar Techy Super Admin for platform
staff. It adds screens, not business logic. Every rule (authorization,
tenant isolation, entitlements, validation, state machines, step-up)
stays where it was: on the server.

Sources: Module Blueprints 06–17, 19, 21–24, 29–33 ("Admin Capabilities",
"Admin UI Requirements"), Module 33 §64–66, Module 22 §10, Module 30 §7.

## 1. Principles

1. **The screen is not the security boundary.** The menu hides what a
   user may not open and marks what the package leaves out. That is
   convenience. Each `/api/v1` call is authorized again by its policy
   and `EntitlementService`. A hidden button changes nothing about what
   the API allows; `AdminUiTest` and the browser checks call the API
   directly to prove it.
2. **Nothing is invented.** A page shows what the API returns. A figure
   the server did not send is shown as "Not available", never as a zero.
   Where the backend has no endpoint, the page says so (Customers) or has
   no control for it (editing an attribute, deleting a warehouse).
3. **The server computes.** No total, balance, stock level, report
   figure or status is calculated in React.
4. **One way to do each thing.** One API layer, one table, one dialog,
   one form helper. A new page is mostly configuration of these.

## 2. Structure

```
resources/js/
  Layouts/AuthenticatedLayout.tsx   the shell: sidebar, top bar, MFA banner, toasts, step-up dialog
  Components/AdminPage.tsx          shell + breadcrumbs + the page's <h1>
  Components/ui/                    the design system (below)
  Components/StepUpDialog.tsx       screen side of the existing step-up
  Components/SettingsEditor.tsx     typed settings (store and platform)
  Components/AuditLogView.tsx       audit trail (store and platform)
  Components/ProductPicker.tsx      server-side product search
  Components/Orders/                payment and shipment drawers
  lib/adminApi.ts                   every API call: timeout, error wording, step-up
  lib/useApi.ts                     load state for a page; paged lists
  lib/useForm.ts                    form state, server validation, single submit
  lib/useUrlState.ts                list filters kept in the URL
  lib/access.ts                     what the user may open (from shared props)
  lib/adminNav.ts                   the navigation and its rules
  Pages/                            one file per page (lazy-loaded)
```

### Design system (`Components/ui`)

Built on the project's Tailwind setup. No component library was added
(the repository has none installed, despite the "shadcn/ui" note in
`vite.config.ts`; adding one was not needed).

| Component | What it guarantees |
|---|---|
| `Button`, `ButtonLink` | Visible keyboard focus; `busy` says so and blocks a second click |
| `TextField`, `TextAreaField`, `SelectField`, `CheckboxField`, `SwitchField` | A real `<label>`; hint and error tied to the control with `aria-describedby`; `aria-invalid`; errors are text |
| `FormError` | One message for the form, with the field messages listed |
| `Dialog` | `role="dialog"`, labelled, focus moves in and back, Tab stays inside, Escape closes (not while busy); `side` renders a drawer |
| `ConfirmDialog` | Says what happens and whether it can be undone; optional typed confirmation; shows the server's refusal |
| `DataTable`, `Pagination` | Loading (skeleton), error with "Try again", empty, rows; caption; secondary columns drop on phones; server pages only |
| `PageHeader`, `Breadcrumbs`, `Card`, `StatCard`, `Tabs`, `Details` | One `<h1>` per page; tabs with arrow keys |
| `QueryState`, `ErrorPanel`, `EmptyPanel`, `Skeleton`, `AccessNotice` | The load states of a page; a 403 is explained as permission or two-step sign-in, not as a fault |
| `PackageNotice`, `UsageMeter` | A package boundary said plainly: what, which package, where to change it; usage as numbers and a bar |
| `StatusBadge` | The server's status in words; colour only supports the text |
| `toast`, `Toaster` | One short message per finished action; a live region |

Motion is limited to colour transitions and the loading pulse; both stop
under `prefers-reduced-motion`.

## 3. The API layer (`lib/adminApi.ts`)

| Situation | Behaviour |
|---|---|
| No answer in 15 s (60 s for uploads and backups) | The request is aborted; error "The server took too long to answer" with "Try again" |
| Network unavailable | "Could not reach the server" |
| 401 | The user is sent to the sign-in page |
| 403 `step_up_required` | The step-up dialog opens; after the server accepts the password the same request is repeated once |
| 403 `mfa_enrollment_required` / `mfa_verification_required` | Explained, with a link to Security |
| 403 other | The server's message, or "You do not have permission to do this" for Laravel's bare refusal |
| 404 | "Not found" state |
| 409, 422 with `code` | The server's message (e.g. invalid transition, refund above balance) |
| 422 validation | Messages under their fields |
| 419 | "Your session expired. Reload the page" |
| 429 | "Too many requests", with the wait from `Retry-After` |
| 5xx | A fixed safe sentence. The server's text is never shown |

Writes that the server treats as idempotent (orders, shipments, refunds,
stock movements, exports, invoice payments) carry one idempotency key per
opened form, so a double submit is one action.

## 4. Navigation and access

`HandleInertiaRequests` shares, through `AdminShellProps`, for display
only: the permission keys of the user's role in the active store,
`is_owner`, the package's feature flags, the package name, the user's
active stores and the store currency.

`lib/adminNav.ts` turns that into the menu:

- **Store Admin** groups (Sell, Catalog, Marketing, Storefront, Insights,
  Store): an entry appears when the role holds one of its permissions.
- **Platform administration**: its own marked group, only for platform
  staff. A platform account without a store sees only this group.
- **Account → Security**: always.
- While `mfa_enrollment_required` is true (a Store Owner without two-step
  sign-in), every entry except Security is shown closed with the reason;
  the banner explains the step. This is the behaviour of commit
  `23c9858`, kept.
- An entry tied to a package feature that the package leaves out is shown
  as unavailable ("Upgrade"), not hidden. No entry needs this today (the
  backend gates features inside pages, not whole modules); the mechanism
  is tested and ready.

Store switching (when a user belongs to several stores) posts to
`/store/switch` and then loads the admin afresh, so no data of the
previous store stays on screen.

## 5. Dates and money

- Dates are shown in the store's timezone (`lib/datetime.ts`, B28). The
  layout keeps the timezone current from the shared props.
- Date-time inputs (promotion start/end, campaign schedule) show and send
  store-local time without an offset; the server reads it in the store's
  timezone (`StoreClock`). `toStoreLocalInput` converts a stored UTC time
  for editing.
- Platform billing dates are UTC and are shown and entered as UTC, with
  the word "UTC" next to them.
- Amounts travel in minor units. `toMinor` converts what was typed on its
  digits (19.99 → 1999, never 1998) using the currency's own number of
  decimals, and refuses more decimals than the currency has.

## 6. Admin UI matrix

Status after B31. "Permission" is what the API requires; a Store Owner
passes all. Step-up means the server asks for the password again.

### Store Admin

| Module | Backend (existing) | UI before | Page route | Permission | Entitlement | Status | What was missing | Security notes | B31 action |
|---|---|---|---|---|---|---|---|---|---|
| Dashboard (M22 §10) | `/dashboard`, `/storefront/setup`, `/store/health`, `/subscription/usage` | Link list only | `/` | `analytics.view` (financial keys need `analytics.financial`) | — | COMPLETE | Figures, setup checklist, launch, health, usage | Each section is requested only if the role may see it | Built |
| Products (M06) | `/products`, `.../variants`, `.../images` | None | `/products`, `/products/new`, `/products/{id}` | `products.view/create/update/delete`, `products.view_cost` | `products.basic`, `max_products` | COMPLETE | List, search, filter, form, variants, images | Cost price is sent and shown only with `products.view_cost` | Built |
| Categories, Brands, Attributes (M07) | `/categories`, `/brands`, `/attributes` | None | `/categories`, `/brands`, `/attributes` | `*.manage` (view with `products.view`) | — | COMPLETE (attributes: create/delete only, as the API) | All | — | Built |
| Inventory (M08) | `/inventory`, `adjust`, `opening-stock`, `movements` | List + `prompt()` adjust (broken: 404) | `/inventory` | `inventory.view/adjust` | — | COMPLETE | Product names, filters, dialogs, history, new record | No stock value: no costing exists | Rebuilt |
| Warehouses (M08) | `/warehouses` | None | `/warehouses` | `warehouses.manage` | `inventory.multi_warehouse` | COMPLETE | All | Second warehouse refused by the server on Basic | Built |
| Reservations (M08) | create/release only, no list | None | — | `inventory.adjust` | — | NOT REQUIRED (no list API; reserved quantity is shown) | — | — | Documented |
| Orders (M09) | `/orders`, `cancel`, `timeline` | List + `prompt()` cancel (broken: 404) | `/orders`, `/orders/new`, `/orders/{id}` | `orders.view/create/cancel` | `orders.basic`, `max_monthly_orders` | COMPLETE | Filters, detail, timeline, create | Status moves only through the server's state machine | Rebuilt |
| Payments (M12) | `/payments`, `transactions`, `manual-confirm`, `refund` | None | `/payments`, order page | `payments.view/manage/refund` | — | COMPLETE | All | Refund above balance refused by the server | Built |
| Shipments (M13) | `/shipments`, `status`, tracking | None | `/shipments`, order page | `shipments.view/fulfill` | — | COMPLETE | All | Invalid status step refused by the server | Built |
| Shipping setup (M13) | `/shipping/zones`, `/methods`, `/rates` | None | `/shipping` | `shipping_config.manage` | — | COMPLETE (add only; no edit/delete API) | All | — | Built |
| Customers (M10) | `/reports/customers`; no list or detail API | None | `/customers` | `analytics.view` | — | BLOCKED BY BACKEND (list, detail, privacy tools need G7) | Customer list | Privacy export/erase API exists but needs a customer list to reach it | Figures shown; gap stated on the page |
| Promotions, Coupons (M14) | `/promotions`, `/coupons` | None | `/promotions` | `promotions.view/manage` | — | COMPLETE (no delete API: archive by status) | All | Times in store timezone | Built |
| Campaigns, Segments (M15) | `/campaigns`, `/marketing/segments` | None | `/campaigns`, `/segments` | `marketing.view/manage` | — | COMPLETE (segments: create only) | All | Sending honours consent server-side | Built |
| Messages (M21) | `/notification-messages`, `/notification-templates` | None | `/notifications` | `notifications.view/manage` | — | COMPLETE | All | Message bodies are not listed | Built |
| Reports (M22) | `/reports/*`, `/exports` | None | `/reports` | `analytics.view/financial/export` | — | COMPLETE | All nine reports, export | Financial tabs only with the permission | Built |
| Pages, Redirects, SEO (M16) | `/content-pages`, `/redirects`, `/seo-settings` | None | `/content/pages`, `/content/redirects`, `/content/seo` | `seo.view/manage` | — | COMPLETE (no sitemap/robots settings API) | All | Page text is sanitized by the server; no script fields exist | Built |
| Theme (M17) | `/store/theme` (draft, publish, rollback) | None | `/storefront/theme` | `theme.view/manage/publish` | `theme.custom_css` | COMPLETE (no draft preview API) | All | Config validated server-side; CSS sanitized | Built |
| Domains (M19) | `/domains` | None | `/domains` | `domains.view/manage` | `domains.custom_domain` | COMPLETE | All | "Verified" only when the server found the DNS record | Built |
| Billing (M29, M04) | `/billing`, `/subscription` | Read-only overview | `/billing` | `billing.view/manage` | — | COMPLETE | Invoice detail, cancel/resume, interval, features | UTC dates | Extended |
| Team (M02) | `/team/*` | Present (G1) | `/team` | `users.*` | `max_staff_accounts` | COMPLETE | Confirm dialogs | No MFA reset action, by decision | Adjusted |
| Roles (M02) | `/roles`, `/permissions` (new) | None | `/team/roles` | `roles.view`; edit: Owner | `custom_roles.enabled` | COMPLETE | All | — | Built |
| Security (M32) | `/auth/mfa` | Present (B29) | `/security` | own account | — | COMPLETE | — | Secret and recovery codes shown once | Kept |
| Backups (M23 §55) | `/backups`, `restore-request` | None | `/backups` | `backups.view/manage/restore` | — | COMPLETE | All | Restore request: step-up + typed backup id; no file download | Built |
| Store health (M24) | `/store/health`, `/history` | Live report | `/store-health` | `store_health.view` | — | COMPLETE | History | — | Extended |
| Settings (M33) | `/store/settings`, history, rollback | None | `/settings` | `settings.view/manage` | — | COMPLETE | All | Only keys the server lists; no free key/value | Built |
| Audit log (M32) | `/audit-logs`, `/integrity` | None | `/settings/audit-log` | `audit.view` | — | COMPLETE | All | Read-only | Built |
| Developer (M31) | `/developer/applications`, keys, webhooks | None | `/settings/developer` | `developer_platform.view/manage` | — | COMPLETE | All | Secrets shown once, kept nowhere | Built |
| Support (M34) | `/support/*` | Present (B26) | `/support` | `support.*` | — | COMPLETE | — | — | Kept |
| Search (global) | None | None | — | — | — | NOT REQUIRED (no backend search) | — | — | Lists search on the server per page |

### Umar Techy Super Admin

All routes need platform staff, two-step sign-in, and the platform
middleware; the page routes themselves refuse store users with 403.

| Area | Backend | Page route | Step-up | Status | B31 action |
|---|---|---|---|---|---|
| Overview (M30 §7) | `/super-admin/dashboard`, infrastructure, backup summary | `/super-admin` | — | COMPLETE | Built |
| Stores | `/super-admin/stores`, store detail, health, domains, subscription | `/super-admin/stores`, `/super-admin/stores/{id}` | — (audited as cross-tenant access) | COMPLETE | Built |
| Users | `/super-admin/users`, deactivate/reactivate | `/super-admin/users` | Yes | COMPLETE | Built |
| Packages (M04) | `/super-admin/packages` | `/super-admin/packages` | Yes | COMPLETE (entitlements read-only: no edit API) | Built |
| Platform billing (M29) | summary, prices, invoices, payments, void, extend | `/super-admin/billing` | Yes | COMPLETE | Built |
| Monitoring (M24 §58) | infrastructure, outbox, API usage, store health, failures | `/super-admin/monitoring` | — | COMPLETE | Built |
| Audit log (M32) | `/super-admin/audit-logs` | `/super-admin/audit-log` | — | COMPLETE | Built |
| Themes, domains, developer apps | `/super-admin/themes`, `/domains`, `/developer/applications` | `/super-admin/catalog` | Suspend app: yes | COMPLETE | Built |
| Platform settings (M33) | `/super-admin/settings` | `/super-admin/settings` | Yes | COMPLETE (no history API) | Built |
| Backups (M23) | B30 | `/super-admin/backups` | Restore: yes | COMPLETE | Kept |
| Support (M34) | B26 | `/super-admin/support` | — | COMPLETE | Kept |
| Impersonation | `/super-admin/stores/{store}/impersonate` returns the store only | — | Yes | NOT REQUIRED (no session switch exists to drive) | Documented |

## 7. Backend changes

Small and additive; see `docs/development/b31-inspection-findings.md` for
why each was needed.

- `AdminShellProps`: shared display props.
- `HasPublicId::resolveRouteBinding`: a ULID in the URL resolves by
  `public_id`.
- `Package::resolveRouteBinding`: by code or numeric key.
- `PackageSeeder`: `products.basic` for all three tiers.
- List filters: products (`search`, `status`), orders (`status`,
  `payment_status`, `search`), inventory (`stock`), payments (`order`,
  `status`), shipments (`order`, `status`).
- Resource fields: `internal_id` (product, variant, warehouse,
  promotion); product `brand_id`, `category_ids`; inventory `product`,
  `variant`, `reorder_quantity`; warehouse `address`, `contact`; order
  `customer`, guest contact, addresses; payment and shipment `order`,
  `created_at`; payment balance in `meta`.
- New read endpoints: `GET /shipping/rates`, `GET /permissions`.
- 38 page routes in `routes/web.php` (28 Store Admin, 10 Super Admin).

## 8. Performance

- Pages are loaded on demand (`import.meta.glob` without `eager`): the
  entry bundle is 364 kB (120 kB gzip); each page is 1–17 kB.
- A page requests each resource once. Sections the role may not see are
  not requested. Lists are server-paged (25 or 50 rows).
- Search waits 300 ms after typing before asking the server.
- `AdminShellProps` adds a handful of small queries (role, permissions,
  package, stores) to an admin page visit, not to API calls.
