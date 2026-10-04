# B34 — Returns completed: damaged stock, photos, guest returns, store credit

Phase B34 builds the parts of returns that B33 listed as not built and that
need no outside provider. Specs read: Module 09 §9, §45–54; Module 08 §46–47;
Module 10 (customer accounts, privacy); Module 12 §46–49 (refunds).

Still not built, because they need a provider (gap G9): courier return labels
and pickup, and a refund sent through a payment gateway.

## 1. Damaged stock (Module 08 §47)

- `inventories.damaged` (migration `2028_07_01_000001`): units that are in the
  warehouse but cannot be sold. They are not in `on_hand`, so `available` never
  counts them.
- `InventoryService::markDamaged()` moves available units (never reserved ones) from on hand to damaged
  (movement `damage_out` with a reason). `writeOffDamaged()` removes damaged
  units for good (new movement type `damaged_write_off`). Neither can take more
  than there is.
- Return inspection now adds damaged units to the damaged balance instead of
  writing them off at once, so staff decide what happens to them.
- API: `POST /inventory/{inventory}/damaged`, `POST /inventory/{inventory}/damaged/write-off`
  (permission `inventory.adjust`, idempotency key). Screen: Inventory list has a
  Damaged column and the actions "Mark damaged" and "Write off".

## 2. Photos on a return request (Module 09 §45 "Images")

- `return_photos` (migration `2028_07_01_000002`), model `ReturnPhoto`.
- `ReturnPhotoService`: every file is decoded and re-encoded by
  `App\Support\ImageReencoder` (shared with product images), which drops EXIF
  data (location) and anything that is not an image. Limits in
  `config/returns.php`: 6 per return, 5 MB, 50–8000 px.
- Files are on a **private** disk (`RETURN_PHOTO_DISK`, default `local`) and
  have no public URL. They are served by the API only, with `nosniff` and
  `Cache-Control: private`, to staff with `returns.view` and to the customer or
  guest who owns the return. The screens load them with the session or token
  and show them from a `blob:` URL.
- Staff with `returns.manage` add and remove photos; the customer adds photos
  while the return is still open (`can.add_photos`).
- Erasure of a customer and removal of a return delete the files.

## 3. Returns for guests (Module 09 §9)

A guest has no account, so the mailbox of the order is the proof of ownership.

- `/returns` on the storefront: the guest enters the order number and email.
  `POST /storefront/returns/lookup` always answers the same `202`, whether or not
  the pair matches, so it cannot be used to find orders (throttled, honeypot
  field). If it matches an order with no registered customer, a link is emailed.
- `GuestReturnLinks`: 32 random bytes; only the SHA-256 is stored; works
  `guest_link_hours` (48); one email per order per 5 minutes. Orders that
  belong to a registered customer get no link — that customer signs in.
- The link opens `/returns?token=…`. The server keeps a well-formed token in the
  visitor's session (per store) and redirects to the clean `/returns`, so the
  token never stays in the address bar, history or `Referer`. `?forget=1`
  clears it. The page is `noindex, nofollow`.
- The guest API (`/storefront/returns/guest…`) takes the token in the
  `X-Return-Token` header and allows the same as a customer's account: see the
  returnable lines, request, withdraw, say it was sent, add photos.
- The customer and guest controllers share `ServesOwnReturns`; the storefront
  component `OrderReturns` takes a `ReturnsApi` (`customerReturnsApi` or
  `guestReturnsApi`), so both use one screen.
- `ReturnService` accepts `Customer|User|null` as actor; a guest's request is
  recorded with `requested_by = guest`.

## 4. Store credit (Module 09 §52)

Tables (migration `2028_07_01_000003`):

- `store_credit_accounts`: one per customer and store, `balance_minor`
  **unsigned** (the database refuses a negative balance), currency.
- `store_credit_entries`: the ledger. Every change is an entry with type
  (`return_refund`, `adjustment`, `spent`, `order_cancelled`, `erased`), signed
  amount, balance after, reason, who did it, the order or return, and an
  idempotency key. Entries cannot be edited or deleted (the model throws).
- `orders.store_credit_minor`, `return_requests.refund_method`,
  `return_requests.refunded_credit_minor`.
- Permission `store_credit.manage` (Owner, Administrator).

`StoreCreditService` is the only writer. It locks the account row, checks the
balance, writes the entry and the new balance in one transaction, records an
audit entry and an outbox event. The same idempotency key never applies twice.

Where credit comes from and goes:

| Event | Effect |
|---|---|
| Staff adjustment (`POST /customers/{id}/store-credit/adjust`, step-up, reason required) | + or −, never below 0 |
| Return refunded "as store credit" | + the refund amount |
| Return of an order that was paid partly with credit | the credit part goes back as credit |
| Checkout with "Use my store credit" (signed-in customer) | − up to the order total; the payment is for the rest |
| Order cancelled | the credit it used comes back |
| Customer erased | balance ended with an `erased` entry |
| Customer merge | accounts and entries move to the kept customer |

Refund rules:

- A refund always settles the payment side first, then the credit side, up to
  what the order used. The sum can never exceed what was paid in both.
- "Refund as store credit" still records the refund on the payment (so the same
  money cannot also be refunded in cash) and credits the customer. It is only
  offered when the order has a registered customer (`store_credit_possible`).
- `Order::payableMinor()` = grand total − store credit; payments are created for
  that amount. An order paid fully with credit is settled with no payment.

Screens: customer page (balance, history, give/take back with reason),
return page (refund as store credit, credit amounts), order page (store credit
and amount to pay), storefront checkout (checkbox with the balance), account
dashboard (balance and history), account order page.

## 5. Also fixed

`/customer/register` and `/customer/login` had throttles without their own key,
so they shared one counter per IP with the storefront API: a shopper who had
just browsed could be refused registration. They now use `customer-register`
and `customer-login` (regression test in `CustomerAuthTest`).

## 6. Not built

> **Update (B35):** store credit expiry (off by default), credit on staff orders and
> photos in the privacy export were built — see `docs/checkpoints/checkpoint-b35.md`.

- Courier return labels and pickup; gateway refunds (gap G9).
- Store credit expiry (§52 "expiry-aware where applicable"): no expiry today;
  it needs the owner's rule.
- Spending credit on orders staff create in the admin; credit for guests.
