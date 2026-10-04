============================================================
PHASE B34 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B34 — Returns completed: damaged-stock balance, photos on return
requests, returns for guests, store credit (G8 follow-up; Module 08
§47; Module 09 §9, §45, §52; Module 12 §46–49)

Starting point:
v1.1 at 0094aeb (B33, CI green). B33 listed as not built: photos,
store credit, guest self-service, damaged balance, courier labels.

Implementation Summary:

1. Damaged stock (commit 324d74b)
`inventories.damaged`; mark damaged (available units only) and write
off (new movement type `damaged_write_off`); return inspection adds to
the damaged balance. Inventory screen: Damaged column and actions.

2. Photos on return requests (commit ab07c93)
`return_photos` on a private disk; every file re-encoded (no EXIF);
6 per return, 5 MB; served only by the API to staff and the owner of
the return. Staff and customers add them; erasure deletes the files.

3. Returns for guests (commit ab07c93 + this commit)
Storefront `/returns`: order number + email → the same answer always;
a link to the order's email (SHA-256 token, 48 h). The link's token is
kept in the session and the address is cleaned; the guest API takes it
in a header. Customer and guest share one return screen.

4. Store credit (this commit)
Accounts (unsigned balance) and an append-only ledger; one service
with row lock, idempotency, audit, outbox. Earned by return refunds
("refund as store credit") and staff adjustments (permission
`store_credit.manage`, step-up, reason); spent at checkout by the
signed-in customer; returned on cancellation and on returns of orders
paid with credit; ended on erasure; moved on merge. Screens: customer
page, return page, order page, checkout, account dashboard and order.

5. Fixed on the way
Registration/login throttles shared the storefront counter (429 after
browsing) — own keys now.

Decisions (details: docs/development/b34-inspection-findings.md §2):
damaged units are a balance; guests prove ownership by the order's
mailbox; refunds go back payment first, then credit; "as store credit"
is recorded on the payment; credit only for account customers; no
expiry until the owner decides; manual credit Owner/Administrator only.

Reused (not duplicated):
ImageReencoder (now shared with product images), InventoryService,
PaymentService, OrderService, ReturnService, NotificationService,
AuditLogger, outbox, CustomerDataService, CustomerMerger, the B31 UI
kit and storefront API layer.

Tests (2026-10-03):
PHP: 1094 passed, 0 failed (1076 before; DamagedStockTest 3,
ReturnPhotosAndGuestTest 7, StoreCreditTest 7, CustomerAuthTest 1).
PHPStan: no errors.
Vitest: 160 passed (151 before; returnsGuestPhotos 5, storeCredit 4).
ESLint, TypeScript: clean. Production build: passes.
composer audit: no advisories. npm audit --omit=dev: 0.

Browser verification — EXECUTED (Chromium via Playwright, local server,
MySQL 8.0, MFA on, production build): 6 of 6 checks.
1. Owner, product Rs. 2,500, 20 in stock, store launched.
2. Mark 3 damaged, writing off 5 refused, write off 1: on hand 17,
   damaged 2, available 17.
3. Staff photo added, shown from a blob URL, anonymous request 401,
   removed.
4. Guest returns page is noindex; a wrong email and the right email get
   the same answer and only the right one creates a link; the link opens
   the order with a clean address; return requested with a photo.
5. Store credit Rs. 500 given by hand; Save stays disabled until a
   reason is given.
6. A return refunded as store credit: balance Rs. 500 → Rs. 3,000; a
   cash refund on top refused (422).
The only console errors were the two deliberate 422s.

NOT EXECUTED — ENVIRONMENT LIMITATION:
- Emails (guest link, approval): mail driver `log`. The real token
  exists only in the email, so the browser check gave the newest local
  link a known token through a helper script outside the repository.
- Gateway refunds, courier labels: no provider connected (gap G9).
- Screen readers; browsers other than Chromium.

Known limitations:
1. No courier return labels or pickup; no gateway refund (gap G9).
2. No store credit expiry; no credit on admin-made orders or for guests.
3. Privacy export does not carry the photo files (finding F3).
4. Tax share of a refund is 0 until the tax engine exists (gap G3).

Requirements closed:
The B33 "not built" items that need no provider (G8 follow-up).

Next:
G15 (catalog depth: collections, import/export, bulk operations —
Module 05 §16, Module 06 §34–51, Module 07). G3 and G9–G10 still wait
for the owner's rules and credentials.
