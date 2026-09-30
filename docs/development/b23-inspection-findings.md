# Phase B23 — Step 1: Inspection + Gap Analysis (Module 29)

Module 29 (Billing, Invoices & Renewals) is how the platform charges
stores for their subscription. It is unrelated to what stores charge
their own customers (Modules 09/12).

## What Existed

| Area | State before B23 | Problem |
|---|---|---|
| Subscription lifecycle | Module 04 statuses (trialing … archived) and `SubscriptionLifecycleService` (trial start, change package, suspend, cancel, expire, reactivate) | Every status change was manual. A trial ended and nothing happened: the store stayed `trialing` forever with full access |
| Prices | None. `packages` had a code and a name only | Nothing said what a package costs |
| Invoices / payments | None | No record of what a store owes or paid |
| Renewal / dunning | None | `past_due` and `grace_period` existed in the enum, but nothing ever set them |
| Store billing page | `Billing/Overview` showed the package and usage, and said "Module 29 Billing owns checkout/payment" | No invoices, no next charge |
| Lifecycle transitions | Status updated in one transaction, audited after it; no row lock | Two concurrent transitions could both read the same previous status |

## Gaps Closed in B23

1. **Prices.** `package_prices` stores a price per package, interval
   (monthly/yearly) and currency, managed by the Super Admin. A price
   change applies from the next invoice; issued invoices keep their
   amounts.
2. **Invoices.** Issued with gap-free numbers (`INV-000001`), an
   itemized line, tax from config (basis points, integer arithmetic,
   half-up) and a `bill_to` snapshot (store name, Owner email). There is
   one invoice per subscription period, enforced by a unique index. An
   invoice is voided, never deleted.
3. **Renewal engine** (`billing:run`, hourly, idempotent):
   - issues the renewal invoice `issue_days_before` (7) days ahead;
   - rolls the period forward once the invoice is paid (or waived) and
     the period starts; a trial converts to `active` at that point;
   - walks unpaid subscriptions down a dunning ladder;
   - ends subscriptions whose cancellation was scheduled.
4. **Dunning ladder**, counted from the due date:
   - 0 days: `past_due`.
   - 3 days: `grace_period` (with `grace_period_ends_at`).
   - 10 days: `suspended`.
   - 40 days: `expired`; the invoice becomes uncollectible.

   Past due and grace period keep access (Module 04 §36: warn before
   cutting off). Paying the invoice reactivates the store at once, in the
   same transaction as the payment.
5. **Payments.** A Super Admin records money received (bank transfer,
   cash, card, mobile wallet), including partial payments. Each payment
   needs an idempotency key, so a retried request never records the same
   money twice.
6. **Super Admin tools:**
   - prices;
   - the cross-store invoice ledger (filter by status, store or overdue);
   - voiding (waives the period);
   - extending a due date (lifts the dunning stage);
   - a revenue summary per currency: MRR, outstanding, overdue, and
     collected this month.
7. **Store owner tools:**
   - plan, next charge and balance;
   - invoices (own store only);
   - cancel at period end, and resume;
   - switch monthly/yearly from the next period.
8. **Notifications.** The store Owner is mailed when an invoice is
   issued, becomes overdue (each stage) or is paid. These go through the
   existing outbox consumer and Module 21's `NotificationService`, and
   are idempotent.
9. **Billing page.** Shows the next charge, an overdue banner and the
   invoice table (new `InvoiceTable` component with Vitest tests).

## Design Decisions

### Every status change goes through `SubscriptionLifecycleService`

The service gained `transitionLocked()`. It changes status and billing
columns on a row the caller has already locked, inside the caller's
transaction, then invalidates entitlements and writes the audit entry.
The engine uses it for every billing-driven change, and the existing
manual transitions now use it too (they now lock the row). The status,
the invoice change, the audit entry and the outbox event therefore
commit or roll back together (ADR-004).

### Lock order: subscription, then invoice

The billing run, payments, voids and due-date extensions all lock the
subscription row first and its invoices second, so they can never
deadlock each other.

### Unpriced packages are never billed or dunned

If a package has no active price in the subscription's interval and
currency, no invoice is issued. The subscription is left exactly as it
is, and the run reports it as `unpriced`. A store must never be
suspended because the platform forgot to set a price. A price of 0 is
different: the invoice is issued already paid and the period renews, so
free plans still leave a record.

### Late invoices are due when issued

A renewal invoice is due when its period starts. If the invoice is
issued late (for example, the scheduler was down), it is due at the
moment it is issued, never in the past. A store is never pushed several
dunning stages at once for an invoice it has only just received.

### Periods follow an anchor

Each period end is `anchor + n × interval`, with no month overflow. The
anchor is the first paid period's start. A Jan 31 subscription is
therefore billed Feb 28, then Mar 31, and does not drift to the 28th for
good.

### Cancellation honours what was paid

Cancellation is scheduled for the end of the last paid period. A
renewal invoice already paid is honoured: the store keeps that period,
then the subscription ends. An unpaid renewal invoice is voided with
reason `subscription_cancelled`.

### Manual suspension outranks billing

`billing_suspended_at` records that billing suspended the store. A
Super Admin's own suspension (e.g. a fraud review) pauses billing
entirely, and a payment never lifts it.

## Bugs Found During the Build (fixed before commit)

| Bug | Found by | Fix |
|---|---|---|
| After a due-date extension, the same invoice could reach the same dunning stage again, and the outbox insert failed on its idempotency key. The whole run for that store then rolled back | `test_extending_the_due_date_lifts_the_dunning_stage` | Overdue event and notification keys include the due date |
| The first draft computed dunning from the period start. An invoice issued after a scheduler outage could jump straight to `suspended` | `test_an_invoice_issued_late_is_due_when_issued_so_the_ladder_never_skips_ahead` | Due date is `max(period start, issued at)` |

## Out of Scope (deliberate)

- A card gateway for platform billing. Payments are recorded manually,
  which is the Module 12 manual-payment precedent.
- Proration on mid-period package changes. A Super Admin package change
  applies its price from the next invoice.
- Refunds and credit notes. Voiding is refused once money was received.
- PDF invoices. The invoice JSON carries every field a document needs.
