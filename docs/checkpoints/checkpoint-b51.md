============================================================
PHASE B51 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B51 — Store lifecycle and closure (gap G14; SRS STORE-003, STORE-009,
MT-017; Module 03 §7–8, §33–37, §40; Module 30 §10, §140; Module 29
§159–160; owner decision 5: 90-day retention)

Owner request (2026-10-14): "b51 start with same passion and professionalism and technical".

Starting point:
- v1.1 at 92537b1 (B50, CI green).
- The store had the statuses pending_setup, active, suspended, cancelled
  and archived, but nothing ever set the last three.
- The storefront gate closed for any status other than active.
- There was no closure, retention, archive or deletion workflow, and no
  store-wide data export for owners on the Basic package.

Implementation Summary:
1. StoreLifecycleService: the only place a store's life changes. It uses
   allowed moves, locks, lifecycle events, audit records and outbox events
   in one transaction. Statuses deleting and deleted were added.
2. The owner can close the store (store name typed, step-up) and reopen it
   within the retention period, with billing restarting. Closing cancels the
   subscription; issued invoices stay due.
3. Umar Techy can:
   - suspend with a reason and a note;
   - lift a suspension (checking the subscription);
   - close on the owner's behalf;
   - archive and restore;
   - set a legal hold.
4. stores:lifecycle (hourly):
   - reminds the owner once before archiving;
   - archives closed stores whose retention has ended (suspended stores
     only by hand);
   - runs approved deletions.
5. Permanent deletion:
   - plan, then request (blockers: not archived, retention not ended,
     legal hold, open invoice);
   - cooling period, in which it can be cancelled;
   - a verified backup taken before deletion;
   - StorePurger removes the store's rows and files, in an order taken
     from the database's foreign keys, with every table classified in
     StoreDataMap;
   - a final count of every purged table; billing and audit records are
     kept.
6. A non-operational store becomes read-only (EnsureStoreOperational, 423).
   Its API keys are refused, marketing is suppressed, and its storefront
   shows "This shop has closed".
7. Data export for the owner: orders, order lines, customers and products
   as CSV.
8. Screens:
   - Settings → Close store, for the Owner, with its own menu entry;
   - a banner on every admin page;
   - Super Admin → Store life card (moves, legal hold, deletion plan and
     progress, history);
   - Stores → Store deletions;
   - a deleted store's record stays readable.

Docs: docs/architecture/b51-store-lifecycle.md, docs/security/b51-security-review.md,
gap matrix (G14 closed; Module 03, STORE-003/009, MT-017).

Found and fixed on the way:
- A purge ordered by RESTRICT keys alone failed on a real store: products
  cascade to inventories, which stock_movements protects. The order now
  includes CASCADE keys.
- A backup that fails on a sync queue threw out of the run. The runner now
  records it as "nothing was removed" and can retry.
- Removed-row counts now include rows that went by cascade.
- BackupAccessBoundaryTest lists the new store restore route, which has no
  link to backups.

Decisions to confirm (design defaults, configurable, not owner-decided):
- Deletion cooling period: 72 hours.
- Reminder before archiving: 14 days.
- Suspended stores are not archived automatically.

Tests:
- New: StoreLifecycleTest (7, 242 assertions); storeLifecycle.test.tsx (7).
- StepUpTest updated with 12 step-up routes.
- PHP suite: 1233 passed, 1 failed (BackupAccessBoundaryTest, fixed and
  re-run: 11 passed); then StoreLifecycleTest gained the failed-backup test.
- PHPStan: no errors.
- Vitest: 242 passed (38 files).
- ESLint and TypeScript clean; production build OK.

Browser check (real Chromium, local server, MFA and step-up on, production
build): 7 checks passed, no console errors.
1. Close store page: steps, open items (20 orders, an unpaid invoice), and
   an orders CSV downloaded.
2. The B46 Tax Store owner closes the store (reason, name, step-up): the
   closed summary shows "90 days left", the banner appears, a change gets
   423 store_closed, and the shop shows "This shop has closed" (503).
3. Reopen (name, step-up): the store and the subscription are active again,
   and the shop returns 200.
4. Super Admin, on a separate local probe store: suspend (policy, note) and
   lift.
5. Close for the owner, retention passed (SQL), archived by
   stores:lifecycle, and the deletion plan dialog; deletion scheduled and
   the cooling period passed (SQL).
   - The first run failed safely: mysqldump was not on that process's
     PATH, so the backup failed and nothing was removed.
   - Try again from the card, with mysqldump on PATH: the real backup was
     verified, 140 rows were purged, the final check found nothing left,
     and 51 audit rows were kept.
6. The deleted store's record and history are readable, and the Stores
   page lists the deletion as checked.
7. 375 px: the Close store page fits.

Not built (b51-store-lifecycle.md §7):
- deleting staff accounts whose only membership was the deleted store;
- ownership transfer, store cloning and store import;
- an Urdu text for the closed-shop page.

CI: pending — to be recorded after the push.

Next (on the owner's word): B52 — security and operations.
