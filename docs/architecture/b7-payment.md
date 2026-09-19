# Phase B7 — Payment Management & Gateways Architecture (Module 12)

See `docs/development/b7-inspection-findings.md` for the scope decision and three
architectural decisions (payment creation timing, 2-tier payment model, refunds
touch `Order.payment_status` only).

## Entities

- **Payment** — one aggregate per Order (`unique(store_id, order_id)`). "Dumb" like
  every other core-state model; `PaymentService` is the only writer.
- **PaymentTransaction** — append-only ledger. B7's documented 2-tier simplification
  of Module 12's Attempt/Transaction split: every attempt AND every financial event
  is one row here, distinguished by `type` (Authorization/Capture/Sale/Void/Refund/
  PartialRefund/Reversal/Adjustment) and `status`.
- **PaymentWebhookEvent** — deliberately NOT tenant-scoped via `BelongsToTenant` (a
  webhook arrives before tenant identity is trusted); `store_id` is populated only
  after the referenced Payment is resolved, for audit/query convenience.

## Order Payment-Status Mapping

Module 12's 14-state `PaymentStatus` (payment engine, detailed) is mapped down to
Order's simpler `PaymentStatus` (B5, summary) by `PaymentService::syncOrderPaymentStatus()`
— the ONE deliberate approximation point:

| Payment engine state | Order summary state |
|---|---|
| Created / Pending / RequiresAction | Pending |
| Authorized | Authorized |
| Paid | Paid |
| PartiallyPaid | PartiallyPaid |
| Failed / Disputed | Failed |
| Cancelled / Expired | Cancelled |
| RefundPending | RefundPending |
| PartiallyRefunded | PartiallyRefunded |
| Refunded / Reversed | Refunded |

`OrderService::syncPaymentStatus()` (new, additive method — `createOrder()`/
`cancelOrder()` untouched) is the ONLY code that writes `Order.payment_status`.

## Gateway Abstraction

`PaymentGatewayContract` (`method()`, `initiate()`, `verifyWebhookSignature()`,
`translateWebhookPayload()`) — `CheckoutService`/`PaymentService` depend only on this
interface. `GatewayResolver` is the one place `PaymentMethod` maps to a concrete
class; adding a real provider later means one new class + one new `match` arm, with
zero changes anywhere else (Module 12 Final Rule #28).

### Adapters Implemented

- **CashOnDeliveryGateway** — no external call; `Pending` until staff records
  collection.
- **BankTransferGateway** — no external call; returns display instructions;
  `Pending` until staff manually verifies.
- **MockRedirectGateway** — a TEST-MODE-ONLY stand-in for JazzCash/Easypaisa/future
  cards. No live HTTP call to any real provider is ever made. Signature scheme:
  HMAC-SHA256 of the raw request body, keyed by the store's own
  `payment_webhook_secret` (constant-time compared via `hash_equals()`).

## Payment Creation Timing

Order is created FIRST (`OrderService::createOrder()`, Phase B5, unmodified), THEN
Payment. `CheckoutService` calls both, in one outer transaction, deriving the
Payment's idempotency key from the checkout-level key (`{key}:payment`) — never a
second, independently-client-supplied idempotency scope.

## Server-Authoritative Amount

`PaymentService::createForOrder()` reads `amount_minor`/`currency` exclusively from
the already-created, already-priced `Order` (itself already server-authoritative
since Phase B5) — no method anywhere in the Payment domain accepts a caller-supplied
amount or currency.

## Zero-Value Orders (Module 12 §14)

If `Order.grand_total_minor <= 0`, `PaymentService::createForOrder()` transitions
the Payment straight to `Paid` (a documented exception to the state machine's normal
`created → pending/requires_action` transitions) and records a zero-amount `Sale`
transaction — no gateway is ever called.

## Webhook Architecture (Module 12 §26-30, Steps 10-11)

`PaymentWebhookController` — no Sanctum, no `staff.principal`/`customer.principal`
(Non-Negotiable Rule #13), registered on its own route file with zero auth
middleware. Trust flow:

1. Dedup on `(provider, external_event_id)` — a genuine replay is a guaranteed
   no-op, checked BEFORE any other processing.
2. Resolve `Payment` from the payload's own `provider_payment_reference` — the URL's
   `{provider}` segment is routing only, never treated as tenant/payment authority
   (Step 10's explicit requirement).
3. Verify signature via the resolved payment's OWN store's `payment_webhook_secret`
   — an unresolvable reference or failed signature is logged (`Ignored`/`Failed`)
   and safely ignored, never treated as authorization for anything.
4. Only after verification: resolve `TenantContext` to the payment's store, then
   process inside a transaction (record transaction → transition Payment → sync
   Order → mark event `Processed`).

The webhook endpoint always responds `200` regardless of internal outcome (signature
failure, unresolvable reference, or success) — the real result is recorded
internally on `PaymentWebhookEvent`, never leaked via HTTP status to a potential
prober.

## Inventory Interaction (Step 15) — No New Reservation Logic

On payment failure (webhook `outcome: failed`), `PaymentService` calls
`OrderService::cancelOrder()` (Phase B5, unchanged), which itself calls
`InventoryService::release()` (Phase B4, unchanged) for every active reservation
tied to the order. No new reservation/release code was written for B7 — this is a
pure reuse of the existing cancellation path, triggered by a new caller.

## Refunds (Module 12 §46-49)

`Payment::refundableAmountMinor()` computes `paid - refunded` from the authoritative
`PaymentTransaction` ledger on every call (never cached/stored). `PaymentService::refund()`
validates the requested amount against this before creating a `Refund`/`PartialRefund`
transaction, is idempotent via its own `idempotency_key`, and — per the dedicated
architectural decision — updates only `Order.payment_status`, never `Order.status`.

## Sensitive Data Discipline (Module 12 §33/§58/§71-72)

- `stores.payment_webhook_secret` — hidden on the model (defense-in-depth) AND never
  included in `StoreResource`'s explicit allow-list.
- `PaymentResource`/`PaymentTransactionResource` never serialize the `metadata`
  column at all — only specific, individually-safe fields.
- `PaymentService` never writes a full raw gateway payload into `metadata` — only
  the already-non-sensitive fields a gateway adapter's own result object returns.

## API Endpoints Added in B7

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/api/v1/payments` | staff | |
| GET | `/api/v1/payments/{payment}` | staff | |
| GET | `/api/v1/payments/{payment}/transactions` | staff | |
| POST | `/api/v1/payments/{payment}/manual-confirm` | staff (`payments.manage`) | COD/Bank Transfer |
| POST | `/api/v1/payments/{payment}/refund` | staff (`payments.refund`, high-risk) | |
| POST | `/api/v1/payment-webhooks/{provider}` | none (signature) | |
| POST | `/api/v1/checkout` | optional (B6, extended) | now also creates a Payment; response shape gained `payment`/`redirect_url` |

## UI

Not built in B7 (Module 12 Step 22 scope, matching B6's own precedent of no
storefront frontend). Deferred, not silently dropped.

## Deferred (see inspection findings for the full, explicit list)

Real JazzCash/Easypaisa/card gateway API integration, payment fees/discounts/limits,
splitting one Order across multiple payment methods, separate authorize-then-capture
customer flow, disputes/chargebacks, reconciliation jobs, payment health monitoring/
analytics, accounting integration, multi-account provider routing, payment UI.
