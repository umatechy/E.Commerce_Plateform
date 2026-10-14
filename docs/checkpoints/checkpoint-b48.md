============================================================
PHASE B48 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B48 — Payment gateways (gap G9, payment side; G21 "card gateway" for
invoices; SRS PAY-001…010; Module 12; Module 30 §26–27; owner decision 2)

Owner request (2026-10-13): "jo abhi nhi bna wo start kro in same passion..
full professional and techinal work but easy to use.. ui and ux par khas
tawajju".

Starting point:
v1.1 at bfcb614 (B47, CI green). Payments had the B7 engine (COD, bank
transfer, a test-only redirect double, HMAC webhooks with event dedupe,
refunds); no real provider, no store bank details (a placeholder text), no
attempts, no reconciliation, no timestamp window.

Implementation Summary:
1. Provider layer: one ProviderAdapter contract with capabilities;
   JazzCash and Easypaisa mobile-account adapters (server to server, no
   credential in the browser); a Test wallet for local/test systems.
   Timeouts are Unknown, never Failed.
2. OnlinePaymentService: attempts (one in flight, at most 5, within the
   payment window), queued encrypted charge tried once, answers applied once
   under a lock, amount/currency checked, retry with the other wallet,
   notifications as hints followed by an inquiry, provider refunds,
   invoice payments through InvoiceService.
3. Reconciliation: payments:reconcile every minute (inquire, expire attempts,
   expire unpaid orders and release stock, flag unknowns) and --deep every 6
   hours (reversals). Findings resolved by a person with a note.
4. Store settings: COD on/off and limits, bank account (step-up), merchant
   accounts per provider (encrypted, masked, sandbox/production, step-up),
   Rs 1.00 sandbox test payment.
5. Platform: providers closed for real money until checked
   (payments.live_providers, Super Admin only, with reason; closing switches
   live accounts off); Umar Techy's own accounts for invoices; health,
   lookup, findings.
6. PAY-008: signed timestamp window on the B7 webhook and the new
   notifications.
7. Screens: Payments setup; Payments → Needs attention; payment drawer with
   attempts; checkout payment cards; Complete your payment; Pay now on the
   customer's order; Pay online for invoices; Super Admin → Payment
   providers. Storefront texts in Urdu.

Conflict reported (not silently resolved): JazzCash and Easypaisa give their
API guides to merchants only; the adapters follow the commonly published
form and are marked NOT VERIFIED. Production stays closed until each is
checked in its sandbox with real sandbox credentials (owner input).

Found and fixed on the way: payments.live_providers could have been changed
(or rolled back) through generic Platform settings, skipping the "switch live
accounts off" step — now refused there. Settings cache: a test-data cleanup
by SQL needs a cache clear (browser script only).

Tests (2026-10-14):
PHP: 1200 passed (PaymentGatewaysTest 15, ProviderAdaptersTest 7,
WebhookTest +2, StepUpTest +8 routes).
PHPStan: no errors.
Vitest: 224 passed (payments.test.tsx 8).
ESLint, TypeScript: clean. Production build: passes.

Browser verification — EXECUTED (Chromium, local server, MFA and step-up
on, production build, B46 Tax Store): 10 of 10.
1. Owner connects the Test wallet (sandbox), offers it; secret never shown.
2. Bank account with live preview; checkout chips update.
3. Wallet declined → retry with another number → paid (attempts
   failed,succeeded).
4. No answer → "Confirming your payment, do not pay again" → paid by itself.
5. One paisa short → "We are checking this payment"; owner resolves under
   Needs attention.
6. Drawer shows attempts; refund through the provider (SIMR- reference).
7. Bank transfer: account and order number with copy buttons.
8. Super Admin: providers, sandbox-only, lookup by reference.
9. Urdu checkout, right to left.
10. 375 px: checkout and Payments setup fit.
Console: 401 from /api/v1/customer/profile on guest pages only — the B25
guest probe, unchanged (not from B48).

Not built (b48-payment-gateways.md §8): real provider verification, cards,
JazzCash/Easypaisa refund APIs, fees, payment-method discounts, split
payments, payment notifications to customers (B50).

Owner inputs needed (at the end, as agreed): JazzCash and Easypaisa sandbox
and production merchant credentials; the card provider.

Next (on the owner's word): B49 — courier adapter, labels, returns.
