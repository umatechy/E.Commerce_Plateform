# Phase B23 — Billing, Invoices & Renewals (Module 29)

## Domain Layout

```
app/Domain/Billing/
  Models/PackagePrice.php          package × interval × currency → amount (platform catalog)
  Models/Invoice.php               tenant-scoped; amounts fixed at issue
  Models/InvoiceLine.php
  Models/InvoicePayment.php        money received; idempotency_key unique
  Models/BillingInterval.php       monthly | yearly, periodEnd(start, anchor)
  Models/InvoiceStatus.php         open | paid | void | uncollectible
  Models/BillingReason.php         subscription_start | subscription_cycle
  Models/InvoicePaymentMethod.php  bank_transfer | cash | card | mobile_wallet | other
  Services/InvoiceNumberGenerator.php  gap-free INV-000001 (locked counter row)
  Services/InvoiceLedger.php       quote / issue / markPaid / markVoid / markUncollectible
  Services/SubscriptionBillingEngine.php  one subscription up to date
  Services/BillingRunner.php       every due subscription, one transaction each
  Services/InvoiceService.php      payment, void, extend due date (Super Admin)
  Services/SubscriptionBillingService.php  cancel / resume / interval (store owner)
  Services/BillingContact.php      the store's active Owner
  Console/RunBillingCommand.php    billing:run (hourly)
  Policies/BillingPolicy.php       billing.view / billing.manage, or Owner
  Exceptions/BillingActionRefusedException.php  code + HTTP status
  Http/Controllers/BillingController.php
  Http/Resources/{Invoice,InvoicePayment,PackagePrice}Resource.php
app/Domain/SuperAdmin/Http/Controllers/SuperAdminBillingController.php
config/billing.php
resources/js/Components/InvoiceTable.tsx (+ test), Pages/Billing/Overview.tsx
```

## Data Model

| Table | Key columns | Notes |
|---|---|---|
| `package_prices` | package_id, billing_interval, currency, amount_minor, is_active | unique (package, interval, currency) |
| `subscriptions` (+) | billing_interval, currency, billing_anchor_at, current_period_started_at, cancel_at_period_end, cancellation_requested_at, billing_suspended_at | index (status, current_period_ends_at) |
| `billing_sequences` | key, next_value | locked counter for invoice numbers |
| `invoices` | number, store_id, subscription_id, package_id, status, billing_reason, currency, subtotal/tax/total/amount_paid (minor), tax_rate_bps, bill_to, period_start/end, issued/due/paid/voided_at | unique (subscription_id, period_start); indexes (store_id, issued_at), (status, due_at) |
| `invoice_lines` | description, quantity, unit/amount minor, period | |
| `invoice_payments` | invoice_id, store_id, amount_minor, method, reference, received_at, recorded_by, idempotency_key | unique idempotency_key |

All money is integer minor units (ADR-003). Currencies are never summed
together.

## Subscription Lifecycle

```
            invoice paid/waived & period starts
 trialing ────────────────────────────────────────▶ active ◀──────────┐
    │                                                 │               │ paid / voided /
    │ period starts, invoice unpaid                   │ same          │ due date extended
    ▼                                                 ▼               │
 past_due ──(+3d)──▶ grace_period ──(+10d)──▶ suspended* ──(+40d)──▶ expired
    (access)             (access)               (no access)          (invoice uncollectible)

 cancel_at_period_end ──(end of last paid period)──▶ cancelled (unpaid renewal voided)
 * billing_suspended_at set; a Super Admin's manual suspension is never touched by billing
```

Day counts are from the invoice due date and are configurable
(`billing.dunning.*`). Dunning only moves forward. A subscription
recovers to `active` only when no open invoice is overdue.

## The Billing Run

`billing:run` resolves the platform context. It selects subscriptions
that are within `issue_days_before` of their period end, or already in
dunning. For each one:

```
DB::transaction:
  lock subscription row
  engine.process(subscription):
     skip unless trialing/active/past_due/grace_period, or suspended by billing
     loop (≤ 36 periods):
        roll forward over periods whose invoice is paid/void and that have started
        period not over  → issue the renewal invoice if within issue_days_before; stop
        period over:
           cancellation scheduled → void open invoices, status cancelled; done
           issue the next-period invoice (null = unpriced; stop)
           zero-total invoice is born paid → loop again to roll forward
     dunning by the oldest overdue open invoice, or recovery
```

A failure in one store is logged and counted, and the command exits
non-zero. The other stores are still billed. The engine is idempotent:
existing invoices are found by (subscription, period start), and each
outbox event has a stable idempotency key.

## Events (ADR-004 outbox)

| Event | Key | Consumers |
|---|---|---|
| `billing.invoice_issued` | `invoice:{id}:issued` | Owner email (skipped for zero totals) |
| `billing.invoice_paid` | `invoice:{id}:paid` | Owner email |
| `billing.invoice_voided` | `invoice:{id}:voided` | — |
| `billing.payment_overdue` (payload `stage`) | `invoice:{id}:overdue:{stage}:{due timestamp}` | Owner email per stage |
| `billing.subscription_renewed` | `subscription:{id}:renewed:{period start}` | — |

Audit entries (Module 32) record every invoice, payment, void, due-date
extension, price change, cancellation, interval change, renewal and
status transition. Each entry sits in the owning store's chain, with the
invoice or subscription as its subject.

## API

Store (staff session; `billing.view` / `billing.manage` or Owner):

| Method | Path | |
|---|---|---|
| GET | `/api/v1/billing` | subscription, upcoming charge (quote), balance |
| GET | `/api/v1/billing/invoices` | own invoices; `status`, `per_page` |
| GET | `/api/v1/billing/invoices/{invoice}` | lines + payments; another store's → 404 |
| POST | `/api/v1/billing/cancel` | at period end; 409 `not_cancellable` / `already_scheduled` |
| POST | `/api/v1/billing/resume` | 409 `not_scheduled` |
| PUT | `/api/v1/billing/interval` | 422 `no_price_for_interval` / `interval_unchanged`; 409 `next_invoice_already_issued` |

Super Admin (`super_admin.platform` group):

| Method | Path | |
|---|---|---|
| GET | `/super-admin/billing/summary` | MRR, outstanding, overdue, collected this month — per currency |
| GET/POST | `/super-admin/billing/prices` | list / create-or-update (201 / 200) |
| PATCH | `/super-admin/billing/prices/{price}` | amount, is_active |
| GET | `/super-admin/billing/invoices` | `status`, `store`, `overdue`, `per_page` |
| GET | `/super-admin/billing/invoices/{invoice}` | |
| POST | `/super-admin/billing/invoices/{invoice}/payments` | `idempotency_key` required; 422 `amount_exceeds_balance`; 409 `invoice_not_open` / `idempotency_key_reused` |
| POST | `/super-admin/billing/invoices/{invoice}/void` | 409 `invoice_partially_paid` |
| POST | `/super-admin/billing/invoices/{invoice}/extend-due-date` | `due_at` in the future, `reason` |

## Configuration (`config/billing.php`)

| Key | Env | Default |
|---|---|---|
| `currency` | `BILLING_CURRENCY` | USD |
| `tax_rate_bps` / `tax_label` | `BILLING_TAX_RATE_BPS` / `BILLING_TAX_LABEL` | 0 / Tax |
| `invoice_prefix` | `BILLING_INVOICE_PREFIX` | INV- |
| `issue_days_before` | `BILLING_ISSUE_DAYS_BEFORE` | 7 |
| `dunning.grace_after_days` | `BILLING_GRACE_AFTER_DAYS` | 3 |
| `dunning.suspend_after_days` | `BILLING_SUSPEND_AFTER_DAYS` | 10 |
| `dunning.expire_after_days` | `BILLING_EXPIRE_AFTER_DAYS` | 40 |

## Tests

`tests/Feature/Billing/` — 31 tests:

- `BillingEngineTest` (14):
  - issue ahead, exactly once;
  - trial conversion and the next cycle;
  - the full dunning ladder;
  - late issue;
  - payment during suspension;
  - partial payment;
  - unpriced and free packages;
  - both cancellation paths;
  - manual suspension;
  - reactivation after expiry;
  - yearly billing and month-end anchors;
  - price changes.
- `StoreBillingApiTest` (7): overview and quote, own invoices only,
  permissions, customer token 401, cancel/resume, interval change,
  registration fields.
- `SuperAdminBillingTest` (8): prices, ledger filters, idempotent
  payments, key reuse, void, due-date extension, summary, access.
- `BillingNotificationTest` (2): the Owner mail sequence (idempotent on
  redelivery), and no mail for zero totals.

Frontend: `InvoiceTable.test.tsx` (4).
