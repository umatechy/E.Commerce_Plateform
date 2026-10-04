============================================================
PHASE B35 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B35 — B34 follow-ups that need no provider (Module 09 §52, Module 32),
and a demo store for testing

Starting point:
v1.1 at 147a324 (B34, CI green). B34 listed as not built: courier
return labels and gateway refunds (G9), store credit expiry, credit on
staff-made orders, photo files in the privacy export.

Implementation Summary:

1. Store credit expiry (Module 09 §52 "expiry-aware where applicable")
Migration 2028_08_01_000001: `store_credit_lots` — what is left of each
credit and its expiry (null = never). Existing balances became one lot
each without expiry. Spending takes from the credit that expires
first. Settings `store_credit.expires` (OFF by default — the policy is
the owner's) and `store_credit.expiry_days` (365, used only when on).
Daily `store-credit:expire` (01:30 UTC) writes what lapsed as an
`expired` ledger entry; idempotent. The customer page and the
customer's account show "Rs. X expires on …". Merge moves the lots.

2. Store credit on orders staff take
`POST /orders` accepts `use_store_credit` for a store customer: the
server takes as much of the total as the balance covers, under a lock,
once (idempotency key), and the ledger names the staff member. A
payment method is then required (for the rest; a zero-value payment is
marked paid). Create order screen: checkbox with the balance.

3. Privacy export carries return photos
Each return in the export has its photos as `data:` URIs (closes B34
finding F3).

4. Demo store — `php artisan demo:store`
Refuses in production. Creates, through the registration path: an
owner, a launched store on a Premium trial, 3 categories with 5
products each (PKR prices, two on sale, one with low stock, one out of
stock, stock in the default warehouse) and one customer account.
Passwords are random and printed once; options --email,
--customer-email, --name, --package. Run on the local database
(store /shop/demo-store-ibbgpy; credentials given to the owner in the
session, not stored in the repository).

Decisions:
Expiry is a mechanism only, off until the owner sets a policy; turning
it on never shortens credit customers already have. Staff spending a
customer's credit needs order creation permission (it only spends
toward a real order of that customer, and cancelling gives it back);
manual credit changes still need store_credit.manage and step-up.

Tests (2026-10-04):
PHP: 1098 passed (1094 before; CreateDemoStoreCommandTest 2,
StoreCreditTest 2; export and merge assertions added).
PHPStan: no errors.
Vitest: 163 passed (160 before; staff order credit 2, expiry 1).
ESLint, TypeScript: clean. Production build: passes.

Browser verification — EXECUTED (Chromium via Playwright, local server,
MySQL 8.0, production build): 5 of 5 checks, no console errors.
1. Demo store: 3 categories of 5; the Men's Clothing page lists exactly
   its 5 products.
2. A product on sale shows Rs. 3,400; the out-of-stock shawl says so.
3. The demo customer signs in to the store.
4. A new owner's Settings show store credit expiry, off.
5. Staff order for a customer with Rs. 500 credit: without a payment
   method it asks how the rest is paid; created with COD it shows
   Rs. 2,000 left to pay; the balance is Rs. 0 and the ledger names
   the staff member.
The demo owner was not signed in by the check, so its two-step
sign-in is still free for the owner to set up.

NOT EXECUTED — ENVIRONMENT LIMITATION:
- The expiry in a browser over real days: covered by a feature test
  with time travel.
- Courier labels, gateway refunds: no provider (gap G9).

Known limitations:
1. No reminder email before credit expires.
2. Credit restored after a cancellation or a return is a new credit
   (with a new expiry when expiry is on), not the old one revived.
3. Demo products have no images.

Next:
G15 (catalog depth). G3 and G9–G10 wait for the owner's rules and
credentials.
