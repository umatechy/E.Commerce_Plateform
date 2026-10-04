# B34 — Inspection findings

Phase B34 builds what B33 left unbuilt and could be built without a provider:
damaged-stock balance, photos on return requests, returns for guests, store
credit. Specs re-read before building: Module 09 §9, §45–54; Module 08 §46–47;
Module 10; Module 12 §46–49; Module 32 (privacy).

## 1. Spec mapping

| Spec | Built |
|---|---|
| M08 §47 damaged stock "tracked separately" | `inventories.damaged`, mark damaged, write off, inspection adds to it |
| M09 §45 return request "Images" | `return_photos`, private, re-encoded, staff and customer upload |
| M09 §9 guest orders "order lookup / support" | emailed link, session token, guest return screen |
| M09 §52 store credit: tenant-scoped | per customer and store |
| M09 §52 auditable | ledger entries + audit log + outbox |
| M09 §52 non-negative | unsigned balance, service check, tested |
| M09 §52 protected from manipulation | permission + step-up, append-only entries, idempotency |
| M09 §52 expiry-aware where applicable | **not built** — needs the owner's rule (§4) |
| M12 §46–49 refund ≤ paid | payment refund + credit back together capped |

## 2. Decisions taken (reported, reversible)

1. **Damaged units are a balance**, not an instant write-off (B33 behaviour
   changed). Staff write them off when they are thrown away. Reason: §47 says
   damaged stock is tracked separately.
2. **Guests prove ownership through the order's mailbox.** No account, no
   password; same as the order emails they already receive. Orders of a
   registered customer get no guest link.
3. **Refund order: payment first, then credit back.** For an order paid partly
   with credit, the money goes back the way it came, payment side first. This
   protects the store from paying cash for credit it gave.
4. **"Refund as store credit" is recorded on the payment too**, so the same
   money cannot also leave as cash.
5. **Store credit only for customers with an account** (a guest has nothing to
   hold it).
6. **No expiry** on store credit until the owner sets a rule.
7. **Manual credit is Owner/Administrator only** (`store_credit.manage`), with
   step-up and a reason — it is a financial act. Managers can see balances
   (`customers.view`) but not change them.

## 3. Findings during the phase

| # | Finding | Action |
|---|---|---|
| F1 | `/customer/register` and `/customer/login` throttles had no key and shared the storefront API counter per IP; registration answered 429 after normal browsing (found in the browser check) | Fixed: own keys; regression test |
| F2 | The guest link token stayed in the address bar after Inertia loaded the page | Fixed: server keeps it in the session and redirects to a clean URL |
| F3 | The privacy export lists returns and store credit but not the photo files | Fixed in B35: photos as data URIs in the export |
| F4 | Checkout response showed a stale payment status when credit paid the whole order | Fixed: order refreshed after spending credit |
| F5 | Pint reports style differences across most of the older codebase (line endings, import order) | Not touched: not a CI gate; a separate formatting-only commit would be cleaner |

## 4. Owner decisions that would change behaviour

None blocks. Policy the owner may set later:
- store credit expiry (e.g. 12 months) and whether expired credit is reported;
- whether staff may spend a customer's credit on orders made in the admin;
- whether guests may receive credit by creating an account afterwards.

## 5. Not built (stated, not hidden)

Courier return labels and pickup, gateway refunds (gap G9); store credit
expiry; credit on admin-made orders; photo files in the privacy export (F3).
