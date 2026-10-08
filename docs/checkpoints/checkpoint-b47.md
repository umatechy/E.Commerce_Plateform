============================================================
PHASE B47 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B47 — Billing completion (gap G21, SRS BILL-006; Module 29 §42–49, §57,
§73–76, §79–82, §92–94; Module 04 §22, §47)

Owner request (2026-10-07): "b47 start kren".

Starting point:
v1.1 at 2932b4f (B46 follow-up, CI green). Billing had invoices, renewal,
dunning, manual payments and voids; no plan change by the owner, no
proration, no refunds or credit notes, no PDF.

Implementation Summary:
1. PlanChangeService: preview (timing, cost now, features lost/gained,
   limits exceeded) and apply — trial: at once; upgrade: at once with a
   prorated invoice (seconds, integers); downgrade: at the end of the billed
   period (scheduled_package_id; the engine switches when that period
   starts); cancel a scheduled change.
2. AccountCredit ledger: pays towards every new invoice first.
3. CreditNoteService: reduce balance / refund / account credit, tax part
   split, limits, CN- numbering, four-eyes approval above a threshold.
4. PaymentNoticeService: "I have paid" by the store, confirmed or rejected by
   platform staff through the one payment path.
5. BillingDocumentRenderer (dompdf): invoice and credit note PDFs from stored
   data, sans-serif, versioned template.
6. Platform settings: tax rate and label on platform invoices (none built
   in), approval threshold, upgrade timing, issuer name and details.
7. Screens: owner Plan and billing (Change plan, I have paid, PDF, account
   credit, notices, credit notes); Super Admin billing (Payments to confirm,
   Credit notes, credit note on an invoice, PDF).

Audit and reuse: InvoiceLedger, the number generator, recordPayment (the only
place a payment is recorded), the billing engine and changePackage were
extended, not copied. The rule "an issued invoice is never changed" from
changeInterval() is followed.

Found and fixed: the first design voided an unpaid renewal to re-price it —
that would have given a free period (void = waived) and could not be
re-issued; found by the test, replaced by "issued invoices stay; a downgrade
starts one period later; an upgrade charges the difference". Outstanding
totals now subtract credit notes.

Tests (2026-10-08):
PHP: 1176 passed (BillingCompletionTest 5; StepUpTest list +5 routes).
PHPStan: no errors.
Vitest: 216 passed (billingCompletion.test.tsx 5).
ESLint, TypeScript: clean. Production build: passes.

Browser verification — EXECUTED (Chromium, local server, MFA and step-up
on, production build): 7 of 7, no console errors.
1. Owner on trial: preview "You move to Business now. Your trial continues";
   changed at once.
2. First invoice; "I have paid" with a transfer reference → "Being checked".
3. Platform staff confirm it under Payments to confirm → invoice paid.
4. Owner downloads the invoice: application/pdf, a real PDF (also read: the
   page shows issuer, bill-to, lines, tax, payment and credit note).
5. Credit note 5.00 as account credit → CN number; owner sees the credit.
6. Mid-period upgrade (20 of 30 days left): 40.00 − 20.00 = 20.00 to pay now;
   the invoice is 20.00 − 5.00 credit = 15.00, reason proration.
7. 375 px: the billing page fits.

Not built (b47-billing-completion.md §10): gateways (B48), debit
adjustments, add-ons, usage billing, statements, notice emails, proration
for the Super Admin override.

CI: not yet run.

Next (on the owner's word): B48 — payment gateway adapters.
