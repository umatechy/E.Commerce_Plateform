# B34 — Security review (damaged stock, return photos, guest returns, store credit)

Scope: everything added in Phase B34 (`docs/architecture/b34-returns-completion.md`).
Reviewed against the master prompt rules: no weakened tenant isolation, no MFA
or step-up bypass, no secrets, frontend never the security boundary.

## 1. Tenant isolation

- New models (`ReturnPhoto`, `StoreCreditAccount`, `StoreCreditEntry`) use
  `BelongsToTenant`; route bindings resolve inside the active store only.
- Guest return links are stored with their store; the token in the session is
  kept per store (`storefront.return_token.{storeId}`), and a token from store A
  opens nothing in store B (tested).
- A customer's store credit is per customer **and** store. `GET /customer/store-credit`
  reads only the signed-in customer's own account; customers are store-bound
  (Module 10), so there is no parameter that could name another store.

## 2. Authorization (server side)

| Action | Rule |
|---|---|
| Mark damaged / write off | `inventory.adjust` or Owner, store membership |
| View return photos | `returns.view`, or the customer/guest who owns the return |
| Add / remove photos (staff) | `returns.manage` |
| Add photos (customer, guest) | own return, while still open |
| View a customer's store credit | `customers.view` |
| Adjust store credit by hand | `store_credit.manage` (Owner, Administrator) **and step-up** |
| Refund as store credit | the existing `payments.refund` |
| Spend credit at checkout | the signed-in customer only; the flag from a guest is ignored |

The buttons in the screens follow the API's `can` flags; the API decides.

## 3. Guest returns — ownership and enumeration

- Ownership proof is the mailbox of the order: the link goes only to the email
  saved on the order. The lookup always answers `202` with the same body, so
  it does not reveal whether an order number or email exists. Throttle
  `5 per 10 minutes` per IP (own key `sf-return-lookup`), honeypot field, one
  email per order per 5 minutes.
- Orders of a registered customer get no guest link (their account is the way
  in), so a guest link can never reach an account's orders.
- Token: 32 random bytes; only SHA-256 stored; 48 h life. It travels in the
  `X-Return-Token` header, never in an API URL. The emailed page link is turned
  into a session value and a redirect to a clean URL, so it is not left in the
  address bar, browser history or `Referer`. The page is `noindex, nofollow`.
- A malformed token is not stored (tested with a script tag).

## 4. Uploaded photos

- Decoded and re-encoded (GD) before storage: no EXIF/GPS, no polyglot files,
  no SVG; size and dimension limits.
- Private disk, random file names, no public URL; served by the API with
  `X-Content-Type-Options: nosniff`, `Cache-Control: private`. An anonymous
  request answers 401 (checked in the browser).
- Files are deleted with the return's personal data on erasure.

## 5. Store credit integrity

- One writer (`StoreCreditService`); row lock (`lockForUpdate`) on the account;
  balance column unsigned so the database itself refuses a negative balance.
- Entries are append-only: the model throws on update and delete.
- Idempotency keys on every entry: a retried checkout, refund, cancellation or
  erasure never applies twice (tested).
- Refunds: the total of payment refund + credit back can never exceed what was
  paid; "as store credit" records the refund on the payment so cash cannot also
  be paid out (browser check: a cash refund on top answered 422).
- Every change writes an audit entry (actor, reason, amount, balance after) and
  an outbox event.
- Manual adjustment requires a reason and step-up (added to `StepUpTest`).

## 6. Rate limits

Every new throttle has its own key. Found and fixed: `/customer/register` and
`/customer/login` had none and shared the storefront API's counter
(regression test added).

## 7. Privacy

Export includes the store credit entries. Erasure ends the balance with an
`erased` entry and deletes photo files. The export does not include the photo
files themselves (finding F3 in `docs/development/b34-inspection-findings.md`).

## 8. Not covered / residual risk

- A person with access to the customer's mailbox can request a return for that
  order; this is the intended proof (Module 09 §9), same as order emails.
- Photos are re-encoded with GD in the request; very large images use memory
  up to the configured limits.
- No malware scanning service is connected (none configured; re-encoding is the
  control).

Dependency audits on 2026-10-03: `composer audit` — no advisories; `npm audit
--omit=dev` — 0 vulnerabilities.
