# B32 — Customer management (gap G7, Module 10) and the remaining admin gaps

Scope: Module 10 §9–10, §13, §24–33, §47–58, §67 on top of the customer model
of B5/B6/B25, and the B31 admin gaps listed in
`docs/development/b31-inspection-findings.md` (customer screens, payment
for staff orders, promotion target names, package contents editing,
platform settings history, theme draft preview, breadcrumbs).

## 1. Data model

Migration `2028_05_01_000001_add_customer_management`:

| Table / column | Purpose | Notes |
|---|---|---|
| `customers.status` | `active`, `blocked`, `archived` (`CustomerStatus`) | default `active`; null read as active |
| `customers.status_reason`, `status_changed_at` | why and when | staff-only |
| `customers.source` | `registered`, `staff`, `import` (`CustomerSource`) | null for rows made before B32 |
| `customers.customer_group_id` | one group per customer | `nullOnDelete` |
| `customer_groups` | name ≤120, description | unique per store |
| `customer_tags` | name + `normalized_name` | unique per store, case-insensitive |
| `customer_tag_assignments` | customer ↔ tag | carries `store_id` |
| `customer_notes` | staff notes | never in a customer-facing API |
| `customer_email_verifications` | one-time email confirmation | only the SHA-256 of the token is stored; 24 h |

Every table carries `store_id` and the models use `BelongsToTenant`;
groups, tags and notes have a ULID `public_id` for URLs.

Migration `…000002_grant_customer_permissions_to_existing_roles` adds
`customers.view`, `customers.manage`, `customers.export`,
`customers.import` and grants them to existing system roles as
`SystemRoles` does for new stores: Administrator all four; Manager view
and manage; Order manager view; Content & marketing view. The Owner has
every permission.

## 2. Services (`app/Domain/Customers`)

| Service | Responsibility |
|---|---|
| `CustomerDirectory` | The list: filters (search, status, group or "none", tag, consent, account, minimum orders, joined from/to in store time), sorts, and per-customer figures (orders, spent, last order — cancelled orders excluded) as subqueries, one query per page |
| `CustomerManagement` | Create (staff), update, block / archive / reactivate, group, tags (≤20, created on use), notes; every change audited |
| `CustomerImport` | CSV preview then confirm (§2.1) |
| `CustomerExport` | Streamed CSV of the filtered list; formula cells defused; audited |
| `CustomerEmailVerification` | Sends and checks the confirmation link |
| `GuestOrderLinker` | Joins earlier guest orders to an account once the address is proven |

### 2.1 Import

1. Upload (CSV, ≤2 MB, ≤2,000 rows; `,` or `;`; a byte-order mark is
   accepted). The header must name `name` and `email`; `phone`, `group`
   and `tags` (separated by `;` or `|`) are optional.
2. Every row is checked: name, email, phone format, group exists, tag
   count and length, duplicate within the file, existing customer.
3. The importable rows are kept **encrypted in the cache for 30 minutes**
   under a key that holds the store and the staff member. Nothing is
   written yet. The preview shows the totals and the problem rows.
4. On confirmation every row is checked again (an email may have been
   taken meanwhile) and created with `source = import`. A confirmed or
   expired import answers 410.

An existing customer is **skipped, never merged** (§56–57).

### 2.2 Status

| Status | Sign in | Order (signed in, as guest with that email, by staff) | Marketing |
|---|---|---|---|
| active | yes | yes | if consented |
| blocked | no (sessions revoked; open tokens refused by `customer.principal`) | no | no |
| archived | no | — | no |

A blocked address cannot register a new account either. A blocked
customer cannot be archived (that would hide the block). History stays.

### 2.3 Guest to registered (§9)

Orders placed as a guest join an account only when the address is
proven: confirming the email link, or resetting the password through the
emailed link. The match is the exact address (case-insensitive) and only
orders without a customer; a name never counts. Registering sends the
link at once; the account dashboard can ask for it again (1 per minute,
3 per 10 minutes).

## 3. API (`/api/v1`, staff, tenant-scoped)

| Method and path | Permission |
|---|---|
| `GET /customers`, `GET /customers/{id}`, `GET …/activity`, `GET …/notes`, `GET /customer-groups`, `GET /customer-tags` | customers.view |
| `POST /customers`, `PATCH /customers/{id}`, `PUT …/tags`, `POST …/block`, `…/archive`, `…/reactivate`, notes, groups, tags | customers.manage |
| `GET /customers/export` | customers.export |
| `POST /customers/import`, `POST /customers/import/{id}/confirm` | customers.import |
| `GET …/personal-data`, `POST …/erase` (now with step-up) | privacy.manage |

Customer API: `POST /customer/email/verification` (signed in),
`POST /customer/email/verify` (from the link).

Every rate limit added here has its own key prefix (see the findings,
defect 6).

## 4. Other changes

- **Staff orders** take `customer` (public id) and `payment_method`
  (`cod`, `bank_transfer`, if the package includes it). The payment
  record is created with the order's idempotency key, so a retry makes
  neither a second order nor a second payment. Staff then record the
  money on the order (existing manual confirmation).
- **Promotions** return `targets: [{id, name}]`; one query per target
  kind per page.
- **Package contents**: `PUT /super-admin/packages/{code}/entitlements`
  (platform staff, step-up, reason required). Only keys the platform
  already knows; the type comes from the existing row. Cached answers of
  every store on the package are dropped. The audit entry holds every
  key's before and after; the Packages page links to that history.
- **Platform settings history**: `GET /super-admin/settings/{key}/history`,
  `POST /super-admin/settings/revisions/{id}/rollback` (step-up), both
  limited to platform keys.
- **Theme draft preview** (Module 17 §19): `POST /store/theme/preview`
  returns a link valid 30 minutes. The token is encrypted and
  authenticated with the application key and names its store. The
  storefront then shows the draft with a banner, `noindex, nofollow`,
  and an "End preview" link; previews are never cached.
- **Breadcrumbs** on Security, Support, Help from the platform, platform
  Backups and platform Support (`AdminCrumbs`).

## 5. Screens

Customers (list with figures, filters in the URL, add, import, export),
Customer detail (profile, figures, orders, addresses, group, tags, notes,
activity, duplicates warning, block/archive/restore, privacy export and
erase), Groups and tags, Create order (customer picker, payment method),
Order detail (customer link), Segments preview (customer links),
Promotions (target names), Packages (contents editor), Platform settings
(history), Theme (Preview draft), storefront Confirm-your-email page and
dashboard prompt.

## 6. Packages (owner decision 2026-10-03)

`customers.advanced` (Business, Premium): groups, tags, CSV import,
CSV export, merge. Basic keeps everything else. Reading existing groups
and tags stays open on Basic (a downgrade deletes nothing); changes
answer 403 `feature_not_entitled`. No "Maximum Customers" limit.

## 7. Customer merge (Module 10 §56)

`POST /customers/{source}/merge` with `into`, `reason`,
`confirm_email` (the target's), behind `customers.manage`,
`customers.advanced` and step-up. `CustomerMerger` locks both rows
(lower id first), checks them again and moves orders, payments,
promotion usages, addresses, tags (union), group (if the target has
none), wishlist items the target lacks, notes, notifications, campaign
history and support requests. The target keeps its identity, password,
email confirmation and marketing consent. The source is archived with
`merged_into_customer_id`/`merged_at`, loses its tokens and can no
longer be changed; `customer_merges` records the counts. Rules and
refusals: `docs/development/b32-inspection-findings.md` §6.

## 8. Not built (out of scope)

- Staff cannot change the email or phone of a customer who has an
  account (§67: identity changes need the customer's verification).
- Customer groups do not set prices or promotion eligibility yet (Module
  14 §17–20 consumes them later).
- Merging two records that both have an account (the customer's choice,
  not staff's).
