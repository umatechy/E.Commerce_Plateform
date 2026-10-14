# B48 — Payment gateways (JazzCash, Easypaisa) and the online payment engine

Gap **G9** (payment side; couriers follow in B49), SRS PAY-001…010; owner
decision 2 (JazzCash, Easypaisa, a card provider — adapters, no invented
credentials). Also the "card gateway" item of **G21**: stores can pay their
Umar Techy invoices online.

Specs read before building: Module 12 (whole module; especially §4, §8,
§10–14, §20–37, §41–49, §51–62, §65–70, §72–75, §84–88, §92–94, Final
Rules), Module 30 §26–27 and Final Rules 18–19, 81, 96, Module 29 §73, Module
04 (no payment tiering beyond §88's "potential model"), SRS PAY-001…011,
owner decisions 2026-09-30 §2.

## 1. Audit first — what existed and what was reused

| Existing (B7 and later) | Decision |
|---|---|
| `PaymentService` — the only writer of payments and transactions; state machine; refunds with a row lock; B7 webhook path with HMAC and event dedupe | kept as the single writer; three methods added (`recordProviderPayment`, `expireUnpaid`, `switchWalletMethod`); refunds take a provider reference |
| `PaymentGatewayContract` + `GatewayResolver` (COD, bank transfer, mock redirect) | kept; a `WalletGateway` joins the resolver for the new methods (creating the payment calls nobody) |
| `MockRedirectGateway` (B7 test double) | kept for its tests; its webhook now needs a signed, recent timestamp (PAY-008) |
| Checkout (`CheckoutService`, `CheckoutController`) | the order is still created first; the server checks the chosen method against this order's payable amount, inside the same transaction |
| `StorefrontExperience::paymentMethods()` (always COD and bank transfer) | now one `PaymentOptions` service: package + store settings + working accounts |
| Settings registry / `ConfigService` / own-page rule of B46 | payment settings live in the registry, changed only on their own page; `ConfigService::clear()` added (a field emptied goes back to its default, with a revision) |
| `InvoiceService::recordPayment()` (B23/B47: the only way an invoice is paid) | wallet payments of invoices go through it, idempotent per attempt |
| Step-up, `AuditLogger`, outbox, tenant scope | used unchanged |

## 2. Data (migration `2029_07_01_000001_payment_gateways`)

- `payment_provider_accounts` — a store's merchant account per provider:
  environment (sandbox | production), on/off, **encrypted** credentials
  (`encrypted:array`, hidden from serialisation), masked hints, optional
  minimum and maximum, last success / failure, first verified.
- `platform_payment_accounts` — Umar Techy's own accounts, for invoices.
- `payment_attempts` — one per try (Module 12 §8): purpose (order | invoice |
  test), provider, environment, **our reference** (`UT` + 18, unique; never
  the provider's id), amount and currency from the server, masked wallet
  (`0300•••4567` — the number itself is never stored), status (pending |
  succeeded | failed | expired | unknown), failure category (Module 12 §59),
  provider code / message / reference, confirmed amount and currency, checks.
- `payment_reconciliation_items` — findings for a person (one per attempt and
  kind).
- `payments.provider`, `payments.reconciliation_status`,
  `payments.client_token_hash` (a guest's key to follow the payment).
- Permissions `payments.provider_config` (Owner only by default — where the
  money goes) and `payments.reconcile` (Owner, Administrator).

Settings: store `payments.cod.enabled|min_order_minor|max_order_minor`,
`payments.bank_transfer.enabled`, `payments.bank.account_title|bank_name|
account_number|instructions`; platform `payments.live_providers` (empty),
`payments.webhook_tolerance_seconds` (300), `payments.order_payment_minutes`
(15; at most 15, the stock reservation time).

## 3. Providers (`app/Domain/Payments/Providers`)

`ProviderAdapter` is the one contract (Module 12 §4, §85): `chargeWallet`,
`inquire`, `refund`, `notificationReference`, `verifyNotification`,
`notificationSentAt`, plus `capabilities()` (§86), `credentialFields()` and
`payerFields()`. Adapters are stateless and never write to the database.
`ProviderHttp` is the only way out: HTTPS only, bounded time; **no answer, a
timeout, a 5xx or an unreadable body is `Unknown`, never `Failed`** (§61–62);
401/403 and other 4xx are failures (nothing charged).

| Provider | Flow built | Refund | Notifications | Status |
|---|---|---|---|---|
| JazzCash | Mobile Account REST (server to server; wallet number + last 6 CNIC digits; approved on the phone); inquiry | in the merchant portal, recorded here | signed (HMAC-SHA256, integrity salt); answers with a hash are checked | **not yet verified in the sandbox** |
| Easypaisa | Mobile account ("MA") REST; inquiry | in the merchant portal, recorded here | not signed: used only as a hint | **not yet verified in the sandbox** |
| Test wallet | the same flow, no network, outcome chosen by the last digits | by API | signed (HMAC over `timestamp.body`) | local and test environments only |
| Card | — | — | — | no provider chosen (owner input) |

Why not JazzCash's browser "page redirection" form: it posts the merchant
password through the shopper's browser, which Module 12 §20 and §33 forbid.
Cards through JazzCash need that hosted page, so they are not built.

**Conflict reported, not silently resolved:** JazzCash and Easypaisa give
their API guides to merchants only. The request fields, response codes and
hash used here follow those guides as they are commonly published; they
**must be confirmed in each provider's sandbox with real sandbox
credentials**. Until then the platform keeps them out of production:
`payments.live_providers` is empty and only Umar Techy can open a provider
(Super Admin → Payment providers, with a reason, step-up). All
provider-specific detail is in one adapter class each.

## 4. The engine (`OnlinePaymentService`)

1. **Checkout** — the shopper picks a wallet and types the number; the
   request is validated *before* an order exists. Checkout creates the order
   and the payment (requires action), checks the method fits this order
   (`PaymentOptions::refusal`, inside the transaction), then starts an attempt
   and hands the guest a token (only its hash is stored).
2. **Attempt** — created under a lock on the payment: no new attempt while one
   waits for an answer (Module 30 §96), at most 5 per order, only within the
   payment window. The charge runs in a queued, encrypted job
   (`ChargeWalletAttempt`, tried once — a second try could charge twice).
3. **Answer** — applied once under a lock on the attempt:
   - paid → the amount and currency must equal the attempt's; then
     `PaymentService::recordProviderPayment` (idempotent per attempt);
   - a different amount/currency → **not paid**, held for a person
     (`amount_mismatch` / `currency_mismatch`);
   - failed → the shopper may try again (same or other wallet);
   - pending / unknown → the page waits; nobody is asked to pay again.
4. **Following it** — the shopper's page asks `GET /checkout/payments/{id}`
   every 3 s; the server asks the provider at most every 15 s while someone
   watches, and `payments:reconcile` every minute for everyone.
5. **Reconciliation** (`payments:reconcile`, every minute; `--deep` every 6 h):
   inquire waiting attempts (30 s apart on the first day, then hourly);
   expire attempts past 10 minutes; expire order payments past the window
   (the order is cancelled and its stock released — PAY-010); flag unknowns
   older than a day; re-check the last two days' paid attempts (a reversal
   becomes `status_changed`). Late money after an order was given up is
   recorded (it arrived) and flagged `paid_after_expiry` — usually a refund.
6. **Notifications** (`POST /api/v1/payment-webhooks/{provider}`) — a hint,
   never proof: exact repeat → no-op; our reference → the attempt; signature
   checked with that store's own credentials where the provider signs;
   timestamp window where it dates; then the engine **asks the provider**.
   Only the reference is kept from the payload.
7. **Refunds** — where the provider refunds by API (Test wallet), the
   provider is asked first and only its confirmation is recorded; otherwise
   the refund is made in the provider's portal and recorded with its
   reference. Never above what was paid (B7 lock).
8. **Invoices** — the same attempts against Umar Techy's account; the invoice
   is paid only through `InvoiceService::recordPayment`.

## 5. Who may do what

| Action | Who |
|---|---|
| See payment settings, attempts, findings | `payments.view` |
| Change COD limits, bank account, merchant accounts | `payments.provider_config` (Owner by default); bank details and accounts also need step-up |
| Resolve a finding | `payments.reconcile` (Owner, Administrator) |
| Refund | `payments.refund` (unchanged) |
| Open/close a provider for real money; Umar Techy's accounts | platform staff, step-up; closing switches every store's live account off at once; `payments.live_providers` cannot be changed through generic Platform settings or a rollback |

Sandbox accounts are **never offered to shoppers** (their payments are not
real money): a store checks one with a Rs 1.00 test payment, then connects
its production account.

## 6. Screens

| Where | What |
|---|---|
| Admin → Store → **Payments setup** | "Your customers can pay with" (live preview); Cash on delivery (on/off, smallest/largest order); Mobile wallets (a card per provider: status, masked details, last payment/problem, Connect/Edit, Send a test payment, Offer at checkout, Disconnect; a warning while a provider is unchecked); Bank transfer (account, live "Customers see" preview); Card payments (coming later) |
| Admin → **Payments** | tabs All payments / **Needs attention (n)** with each finding explained and Resolve; the payment drawer shows every online attempt and refunds the right way |
| Storefront **checkout** | payment cards with a one-line explanation; a method outside its limit shown disabled with the reason; the wallet number field inline; bank details with copy buttons; "Place order and pay Rs …" |
| Storefront **Complete your payment** | Check your phone (steps, held-until time) → Payment received; Confirming (do not pay again); We are checking this payment; Payment not completed → try again with the same or the other wallet |
| Storefront **account order** | "Pay now" for an order still waiting for its payment |
| Admin → **Billing** → invoice | "Pay online (JazzCash / Easypaisa)" when Umar Techy has an account open |
| Super Admin → **Payment providers** | capability matrix, sandbox-checked flag, open/close for real money with a reason, store counts, last 24 h, Umar Techy's accounts, lookup by reference, every store's open findings |

All storefront texts are in Urdu too (right to left).

## 7. Tests

- `PaymentGatewaysTest` (15): settings and encryption, wallet checkout, refused
  number, decline + retry + token + isolation, unknown + reconciliation,
  amount mismatch + resolve, expiry + stock, notifications (forged, old,
  valid), provider refund, COD limits + bank details + own-page rule,
  permissions, sandbox/production/live switch, tenant isolation, invoice
  payment, live list validation.
- `ProviderAdaptersTest` (7, faked HTTP): JazzCash hash, charge request,
  answer mapping, bad hash, timeout; Easypaisa header and statuses; numbers.
- `WebhookTest` +2: replay window, missing timestamp. `StepUpTest`: 8 routes.
- Vitest `payments.test.tsx` (8).

## 8. Not built here

- Real provider verification (needs sandbox credentials — owner input).
- Cards (no provider chosen — owner input); tokenised methods, disputes.
- Provider refund APIs for JazzCash/Easypaisa; payment fees (§38),
  payment-method discounts (§39), split payments across methods (§40).
- Emails/WhatsApp on payment events (outbox events exist:
  `payment.succeeded`, `payment.attempt_failed`, `payment.expired`,
  `payment.reconciliation_flagged`) — B50.
- Guests who closed the page cannot reopen it without the token (signed-in
  customers can, from their order).

## 9. Before real money

1. Owner obtains JazzCash and Easypaisa sandbox credentials (outside Git).
2. Connect them as a store's sandbox account; run: a payment, a decline, a
   timeout (no approval), an inquiry, a notification. Correct the adapter if
   a field or code differs (one class each).
3. Mark the adapter verified in code; open the provider under Super Admin →
   Payment providers with the reason.
