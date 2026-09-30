============================================================
PHASE B23 CHECKPOINT
============================================================

Phase:
Development Phase B

Module:
B23 — Billing, Invoices & Renewals (Module 29)

Implementation Summary:
Before B23, subscriptions had statuses but nothing ever changed them: a
trial ended and the store stayed on trial forever. The platform now
prices packages per interval and currency and issues gap-free invoices
ahead of each period. An hourly, idempotent engine rolls paid periods
forward, converts trials, and moves unpaid stores along a dunning ladder
(past due → grace period → suspended → expired). It also ends scheduled
cancellations. A Super Admin records payments idempotently, voids
invoices or extends due dates, and sees revenue per currency. Store
owners see their plan, next charge and invoices, and can cancel, resume
or change their billing interval. Every change is audited in the
Module 32 trail and published through the ADR-004 outbox. The Owner is
emailed at each billing event.

New Components Implemented:
App\Domain\Billing — PackagePrice, Invoice, InvoiceLine, InvoicePayment,
BillingInterval, InvoiceStatus, BillingReason, InvoicePaymentMethod,
InvoiceNumberGenerator, InvoiceLedger, SubscriptionBillingEngine,
BillingRunner, InvoiceService, SubscriptionBillingService,
BillingContact, billing:run command, BillingPolicy,
BillingActionRefusedException, BillingController, Invoice/InvoicePayment/
PackagePrice resources; SuperAdminBillingController; config/billing.php;
SubscriptionLifecycleService::transitionLocked(); billing mails in
NotificationEventRouter; InvoiceTable component and the updated
Billing/Overview page. See docs/architecture/b23-billing.md.

Database Changes:
- New: package_prices, billing_sequences, invoices, invoice_lines,
  invoice_payments.
- subscriptions: billing_interval, currency, billing_anchor_at,
  current_period_started_at, cancel_at_period_end,
  cancellation_requested_at, billing_suspended_at.

Permissions:
billing.view, billing.manage (PermissionSeeder; Owner only by default).

Scheduler:
billing:run hourly (withoutOverlapping).

Automated Test Status:
EXECUTED.
- Backend: 785 tests, 1687 assertions — all passing on MySQL 8.0
  (31 new Module 29 tests in tests/Feature/Billing/).
- Frontend: 9 Vitest tests passing (4 new); `npm run lint`,
  `tsc --noEmit` and `npm run build` pass.
- Static analysis: PHPStan/Larastan level 5 — no errors.

Runtime Verification Status:

| Area | Status |
|------|--------|
| Migrations up / rollback / up (MySQL 8.0) | EXECUTED |
| PHPUnit suite (MySQL 8.0, Redis) | EXECUTED — PASSING |
| PHPStan level 5 | EXECUTED — CLEAN |
| Frontend lint / typecheck / Vitest / Vite build | EXECUTED — PASSING |
| billing:run against the dev database (issue + dunning, second run idempotent) | EXECUTED |
| audit:verify after billing activity | EXECUTED — chains intact |
| GitHub Actions CI run | NOT EXECUTED — runs on the next push to main/develop or a PR |
| Real payment gateway for platform billing | NOT EXECUTED — payments are recorded manually by design |

Known Limitations:
- No card gateway for platform billing; no proration, refunds, credit
  notes or PDF invoices.
- No maker-checker approval on voids or large payments.
- The billing page is read-only; cancel/resume/interval are API actions.

Recommended Next Milestone:
Module 34 (Support) or Module 05 (Storefront), at the product owner's
choice. Running the GitHub Actions workflow on a pull request for
B21–B23 should come first.
