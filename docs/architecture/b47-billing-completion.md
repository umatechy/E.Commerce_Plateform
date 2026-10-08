# B47 — Billing completion

Gap **G21** (billing depth), SRS BILL-006. What Umar Techy bills stores for
their subscription (Module 29) — not what stores bill their customers.

Specs read before building: Module 29 §42 (refunds), §43 (credit notes),
§45–46 (account credit and its application), §47 (proration), §48–49
(upgrade, downgrade: "must not silently delete data"), §57–58 (tax on the
platform's invoices), §73–74 (bank transfer, manual payment), §75–76 (PDF
documents, document versioning), §79–82 (payments to confirm, partial
payments), §88–89 (billing portals), §92 (financial approval), §93–94 (audit,
financial immutability), §95–97 (integer money, numbering); Module 04 §22,
§47 (downgrade over-limit detection and preview).

## 1. Audit first — what was reused

| Need | Existing code (B23) | Decision |
|---|---|---|
| Invoices, numbering, payments | `InvoiceLedger`, `InvoiceNumberGenerator`, `InvoiceService::recordPayment()` (idempotent, partial payments) | kept; credit notes get their own number series from the same generator; payment notices are confirmed **through** `recordPayment()` |
| Renewal and dunning | `SubscriptionBillingEngine` | kept; one addition — a scheduled downgrade takes effect when its period starts |
| Package change | `SubscriptionLifecycleService::changePackage()` (immediate, no money) + private over-limit detection | the plan change service calls it; over-limit detection made public for the preview; the Super Admin override route is unchanged |
| "An issued invoice is never changed" | `changeInterval()` refuses once the next period is invoiced | the same rule here (see §3) |
| Platform tax | `config('billing.tax_rate_bps')` (env, default 0) | now a platform setting with that value as its start; no rate is built in |
| PDF | nothing | `dompdf/dompdf` added (local rendering, no remote resources) |

## 2. Data

Migration `2029_06_01_000001_billing_completion`:

- `subscriptions.scheduled_package_id` — a downgrade waiting for the period end;
- `invoices.credit_applied_minor` (account credit used), `amount_credited_minor`
  (credit notes against it), `document_version`; `invoice_lines.kind`
  (`charge` / `credit`); billing reason `proration`;
- `credit_notes` — number (CN-000001…), invoice, status (pending approval /
  issued / rejected), settlement, amounts with their tax part, reason, refund
  method and reference, who asked and who approved;
- `billing_credits` — the store's account credit ledger (+ added, − used),
  append-only;
- `payment_notices` — a store's "I have paid" with method, reference, date,
  amount and its review.

Platform settings: `billing.tax_rate_bps`, `billing.tax_label`,
`billing.approval_threshold_minor` (0 = off), `billing.upgrade_timing`
(`immediate_prorated` / `next_period`), `billing.issuer_name`,
`billing.issuer_details` (address, NTN — printed on PDFs).

## 3. Changing the plan (`PlanChangeService`)

The owner sees a **preview** first (`GET /billing/plan-change/preview`): when
it happens, what it costs now, features lost and gained, limits the store
would be over.

| Case | What happens |
|---|---|
| Trial | the new package at once, nothing charged |
| Upgrade (higher price) | at once; an invoice for the rest of the period: new plan × remaining ÷ period − old plan × remaining ÷ period (seconds, half-up, integers), plus tax; due now |
| Downgrade (lower price) | at the end of the period already billed; the renewal is issued for the new package and the engine switches the package when that period starts. Nothing is deleted |
| Same price | at once, nothing charged |

Rules that keep invoices immutable (Module 29 §94):

- an invoice already issued for the next period is **never changed or voided**
  (a voided invoice counts as a waived period, and one period has one
  invoice): a downgrade then starts one period later; an immediate upgrade
  also charges that period's difference;
- a scheduled downgrade can be cancelled until the renewal for the new
  package is issued;
- an overdue invoice, or an unpaid invoice for the current period, must be
  paid before an upgrade;
- unused value is credited only for a period that was paid (a waived period
  gives nothing back).

## 4. Account credit (`AccountCredit`)

Per store and currency. It comes from credit notes settled as credit; it
pays towards **every new invoice first** (renewals and prorations), and an
invoice it covers completely is settled at once. The invoice shows "Account
credit used". No expiry; not transferable.

## 5. Credit notes and refunds (`CreditNoteService`)

A credit note corrects an issued invoice without changing it:

| Settlement | For | Effect |
|---|---|---|
| `reduce_balance` | an open invoice | less is due; a balance of 0 settles it (the store recovers at once) |
| `refund` | a paid invoice | money paid back — method and reference recorded |
| `account_credit` | a paid invoice | added to the store's account credit |

Amounts include tax; the tax part is split in the invoice's own ratio.
Refunds and credits together never exceed what was paid on the invoice; notes
waiting for approval already count.

**Approval (§92):** at or above `billing.approval_threshold_minor` a note
waits; **another** member of the Umar Techy team approves or rejects it
(four eyes). Issued notes are immutable.

## 6. Payment notices (`PaymentNoticeService`)

Until live gateways exist (B48), a store pays by bank transfer, JazzCash,
Easypaisa or cash and reports it: amount, method, reference, date. It is a
notice only — the invoice is paid when Umar Techy staff confirm it (step-up),
which records the payment once (idempotent per notice). A rejected notice
shows the store the reason.

## 7. Documents (`BillingDocumentRenderer`)

Invoice and credit note PDFs from the stored document only (lines, tax,
bill-to snapshot, payments, credit notes) — never from current prices.
Sans-serif (DejaVu Sans), A4, issuer name and details from platform settings,
template version in the footer and on the record.

## 8. Screens

| Place | What |
|---|---|
| Admin → Plan and billing | "Change plan" with preview; scheduled change with "Keep …"; invoice dialog: Download PDF, "I have paid"; cards: Account credit, Payments you reported, Credit notes (PDF) |
| Super Admin → Platform billing | tabs **Payments to confirm** and **Credit notes** (approve / reject); invoice dialog: Credit note, Download PDF; refunds in the monthly figures |
| Super Admin → Platform settings | the six billing settings |

## 9. Found and fixed on the way

- Voiding an unpaid renewal to re-price it (first design) would have given a
  **free period** (a void invoice is "waived") and could not be re-issued
  (one invoice per period). Found by the test; replaced by the rule in §3.
- Outstanding and overdue totals now subtract credit notes.

## 10. Not built here

- Card and wallet gateways with automatic collection — B48.
- Debit adjustments (§44), add-ons and usage/overage billing (§50–54),
  billing statements (§77), unapplied/over-payments (§79–80), reseller,
  domain and marketplace billing.
- Email to the store when a notice is confirmed or rejected (outbox events
  exist: `billing.payment_notice_submitted`, `…_rejected`,
  `billing.credit_note_issued`, `billing.plan_changed`).
- Proration for the Super Admin's direct package change (it stays an audited
  override without charge).
