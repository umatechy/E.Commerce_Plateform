# B51 — Store lifecycle and closure (gap G14)

Sources:
- SRS STORE-003, STORE-009, MT-017.
- Module 03 §7–8 (lifecycle), §33–37 (suspension, reactivation, cancellation, archival, deletion), §38 and §40 (data export).
- Module 05 §5 (storefront per lifecycle state).
- Module 29 §159–160 (billing impact of cancellation and deletion).
- Module 30 §10 (lifecycle control) and §140–141 (tenant deletion control).
- Module 35 §42 (tenant deletion and archive).
- Bible §112, §188–189.
- Owner decision 5: closed and suspended stores are kept 90 days after closure or suspension, longer only when law or contract requires it.

## 1. States and moves

The store keeps its own operational status. Trial, past due and grace period remain subscription states kept by billing (B44, `StoreLifecycle`), so the same thing is never recorded in two places.

| Status | Meaning | Admin | Storefront |
|---|---|---|---|
| `pending_setup` | Not launched yet | Normal | Not launched (staff preview) |
| `active` | Running | Normal | Open (if the subscription allows) |
| `suspended` | Restricted by Umar Techy | Read-only | "Temporarily unavailable" |
| `cancelled` | Closed; kept for the retention period | Read-only; data can be exported; can be reopened | "This shop has closed" |
| `archived` | Past the retention period; kept for records | Billing and support only | Closed |
| `deleting` | Approved deletion under way | Billing and support only | Closed |
| `deleted` | Data removed; the store row stays (soft-deleted) for financial references | — | Closed |

Allowed moves (`StoreLifecycleService::TRANSITIONS`; any other move is refused):

| Action | From | By |
|---|---|---|
| close | pending_setup, active, suspended | Owner, or Umar Techy on the owner's behalf |
| reopen | cancelled, within retention and not suspended | Owner or Umar Techy |
| suspend | pending_setup, active, cancelled | Umar Techy, with a reason and a note |
| reactivate | suspended | Umar Techy; the subscription must allow access or be reactivated with it |
| archive | cancelled (automatically when retention ends), suspended (by hand) | System or Umar Techy |
| restore | archived → cancelled with a new retention period | Umar Techy |
| request / cancel deletion | archived | Umar Techy |

Every move:
- locks the store row;
- writes a `store_lifecycle_events` row, an audit record (`store.{action}`) and a `store.lifecycle_changed` outbox event, all in one transaction (ADR-004);
- sends the owner an email (`StoreLifecycleNotifier`).

## 2. Closing (owner)

On **Settings → Close store**, shown only to the Owner, from top to bottom:

1. **What closing means:** the shop closes at once, billing stops, the admin turns read-only, the owner can change their mind for 90 days, and after that the store is archived.
2. **Still open:**
   - unfinished orders and open returns;
   - store credit balances;
   - unpaid Umar Techy invoices;
   - custom domains.

   These are warnings only. Closing is the owner's right.
3. **Downloads** (CSV, protected against spreadsheet formulas, audited): orders, order lines, customers and products. The customers export is not package-gated here, because it is the owner's own data on leaving.
4. **Close:** an optional reason and feedback, the store's name typed in full, then step-up.

Effects:
- The subscription is cancelled at once (`subscription_cancelled`, reason `store_closed`). No new invoices are issued, and issued invoices stay due (Module 29 §159).
- The data is kept until `retention_ends_at` (`stores.closure_retention_days`, default 90, range 90–3650).
- About 14 days before that date the owner gets one reminder.
- When the date passes, the hourly `stores:lifecycle` archives the store.

Reopening (owner, store name typed again, step-up) returns the store to the status it had before. If the subscription had lapsed, billing restarts from today (Module 04 §38).

## 3. What a non-operational store can do

`EnsureStoreOperational` runs on the whole signed-in staff API:

- **suspended or cancelled:** reads are allowed, including the exports; changes return **423** (`store_suspended` / `store_closed`).
- **archived, deleting or deleted:** only the routes that are always open (below) work.
- **Always open:** sign-in and security (`auth/*`), store switching, billing, the store's subscription, support from Umar Techy (`platform-support/*`) and the closure page.
- Super Admin routes are not affected.

Also:
- Developer API keys of a non-operational store get **423** `store_not_operational` (Module 31 §2.27).
- Marketing messages (campaigns, abandoned carts) from such a store are recorded as suppressed.
- Every admin page shows a banner explaining why the store is read-only, with the way out (reopen or contact support).

## 4. Permanent deletion

The flow follows Module 30 §140: request → validate → billing check → legal/retention check → backup → confirm → archive → retention → hard delete → verify.

1. **Plan** (`GET …/lifecycle/deletion-plan`) counts the rows to be removed in each table, and the rows that are kept. Nothing changes.
2. **Request** (Super Admin, step-up, a reason of at least 10 characters, the store's address typed in full). It is refused when:
   - the store is not archived, or its retention period has not ended;
   - it has a legal hold;
   - it has an open Umar Techy invoice (it must be paid, voided or written off as uncollectible; Module 29 §160);
   - another deletion is already open.
3. **Cooling period** (`stores.deletion_cooling_hours`, default 72). During it the deletion can be cancelled, and the owner is emailed the date.
4. **Run** (`stores:lifecycle`, `StoreDeletionRunner`), one step per run:
   - The checks are made again.
   - A platform backup "before a store deletion" (pre-change tier) is taken. Nothing is removed until that backup is **verified**. A failed backup fails the deletion with "nothing was removed", and it can be retried.
   - The store turns `deleting` and `StorePurger` removes its rows and files.
5. **Final check:** every purged table is counted again. Only when all are zero is the store marked `deleted`, soft-deleted, with `remaining = []`. Otherwise the deletion fails and a retry continues where it stopped.

### StoreDataMap

Every table with a `store_id` column must appear in exactly one list. A test fails when a new table has no decision, and the purger refuses to run.

- **RETAIN** (legal, financial and accountability records):
  - audit_logs, store_lifecycle_events, store_deletions;
  - subscriptions, invoices, invoice_payments, credit_notes, billing_credits, payment_notices;
  - backups (they expire through the backup retention policy).
- **PURGE:** everything else (97 tables), including customers' personal data, orders, payments taken by the store, settings, provider accounts, memberships and domains.

Order of deletion:
- It comes from the database's own foreign keys. A table is emptied before every table it points to with RESTRICT, NO ACTION or CASCADE.
- CASCADE must be included. A real run found that `products` cascades to `inventories`, which `stock_movements` protects with RESTRICT.
- Rows are deleted in batches of 500 (`DELETE … LIMIT`), so constraints never need to be switched off.

Files are removed before their rows (product images, store media, return photos, shipping labels, report exports). Then the store's own folders are swept on every disk involved.

## 5. Super Admin

The **Store page → Store life** card shows:
- the status, dates and days left;
- the moves allowed from here, each with a dialog asking for a note (suspension also needs a reason);
- legal hold;
- the deletion panel: the blockers, the plan dialog showing what is removed and what is kept, its progress, and cancel or try again;
- the full history.

A deleted store's record and history stay readable (`withTrashed`). The **Stores** page lists every deletion with its final check.

The existing subscription suspend button is now labelled "Suspend subscription", to keep it apart from suspending the store.

## 6. Settings (platform)

| Key | Default | Range |
|---|---|---|
| `stores.closure_retention_days` | 90 (owner decision 5) | 90–3650 |
| `stores.deletion_cooling_hours` | 72 (design default; owner may change) | 24–720 |
| `stores.closure_reminder_days` | 14 (design default) | 1–60 |

## 7. Not built / limits

- Staff user accounts whose only membership was the deleted store remain as accounts. Their personal data follows Module 02 and Module 32 (a separate account deletion is not built).
- Data in backups leaves only as those backups expire (Module 23).
- Ownership transfer (§28), store cloning (§52) and store import (§41) are not built.
- Deletion runs in batches inside the scheduler. Very large stores may need several runs, and every run continues where the last one stopped.
- The storefront "closed" page text is in English only, like the existing unavailable page.
