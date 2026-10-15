============================================================
PHASE B57 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B57 — The store affiliate program (gap G20, part 1):
- SRS AFF-001, AFF-002, AFF-003, AFF-005.
- Module 28; Module 04 (entitlement), Module 30 (step-up), Module 32 (audit).

Owner request (2026-10-15): "ab g20 start kren in same passion dont bored,
work in fresh mode. professional and tehnical work. ui ux lazmi".

Split of G20:
- B57 (this phase): the program engine and the store affiliate program.
- B58 (proposed): the platform reseller program — reseller pricing,
  provisioning stores for clients, platform package referrals with
  recurring commissions (AFF-004).

Starting point:
- v1.1 at bc285a1 (B56, CI green). Nothing of Module 28 existed.

Implementation Summary:
1. Program and rules (Marketing → Affiliates → Program settings).
   - Percentage (basis points) or fixed amount, attribution window (≤ 90
     days), hold period, after delivery, after payment, minimum order, cap
     per order, new customers only, payout threshold, self-referral review
     or reject, terms.
   - Each change is a new immutable version; commissions keep a snapshot.
     A program has no version until the owner saves rules.
   - Starting needs the package feature `affiliate.programs`.
2. Affiliates.
   - Added by staff, or applied on the storefront ("Become an affiliate"
     page and footer link): honeypot, throttle, the same answer for known
     addresses; the owner approves or declines.
   - Referral code (unique per store); optional coupon linked to them.
   - Private page link emailed; shown once to staff; only its hash is
     stored; a new link voids the old one.
3. Attribution.
   - `?ref=CODE` on any storefront page gives an HMAC-signed token, kept in
     the shopper's browser for the window; checkout sends it.
   - The coupon wins over the link; one commission per order; the server
     checks store, window and affiliate again.
   - Same customer account, email or phone → held for review (or rejected).
   - Clicks counted once per network and day; no IP stored.
   - An affiliate error never blocks an order.
4. Lifecycle (`affiliates:follow-up`, hourly): cancelled → rejected;
   refunds reverse the commission proportionally, and after payment become
   a negative adjustment; approval after delivery, payment and the hold.
5. Payouts: prepare (step-up; the full account is shown and audited), pay
   outside the platform, record the reference (step-up); cancel; one open
   payout per affiliate; threshold; adjustments with a reason.
6. The affiliate's private page on the storefront: link, code, coupon,
   visits, orders, balances, commissions, payouts, adjustments, payout
   details. No customer data.
7. UI: Marketing → Affiliates (Overview, Affiliates with drawer,
   Commissions with review reasons, Payouts, Program settings); storefront
   join page and private page in English and Urdu (58 new Urdu strings).
8. Help: "Run an affiliate program" (store teams), "Earn as an affiliate"
   (shoppers); release notes 1.1 updated.

Docs:
- docs/architecture/b57-affiliates.md
- docs/security/b57-security-review.md
- gap matrix: Module 28 row, AFF-001–005, G20.

Found and fixed on the way:
- Paying needs the full account, but the admin showed only the last four
  digits. Added the step-up `pay-to` reveal, audited every time.
- The join page and the applicant email promised an email after any
  decision; declined applicants get none. Both now say only approval is
  emailed.
- The private page re-fetched in a loop because useT() returns a new
  function each render; the fetch no longer depends on it.

Decisions to confirm (owner):
- `affiliate.programs` is in Business and Premium, not Basic. Module 04 does
  not place affiliates in a package.
- Payouts are manual (the store pays and records the reference). No payout
  provider is connected, and none is assumed.
- Declined applicants are not emailed.
- Default rules: 10%, 30-day window, 14-day hold, after delivery and
  payment, self-referral held for review.

Tests:
- New: AffiliateProgramTest (9 tests); affiliates.test.tsx (7).
- Adjusted: StepUpTest (5 new step-up routes).
- PHP suite: 1296 passed.
- Vitest: 261 passed.
- PHPStan, ESLint and TypeScript are clean. Production build OK.
- api:openapi --check is up to date; help:check is valid (62 articles).

Browser check (real Chromium; the B46 Tax Store owner with MFA and step-up;
a phone-sized shopper): 12 of 12 passed, no 5xx.
1. Affiliates in the Marketing menu; "Not set up".
2. Rules saved (version 1), program started.
3. Affiliate added; private link shown once; token not stored readable.
4. Shopper arrives through `?ref=`: token kept on the device; one click.
5. Footer link → join page → application (pending).
6. Cash-on-delivery order with the referral: 10% commission, pending.
7. The owner sees it under Commissions with the rules version.
8. Delivered and paid → follow-up approves it.
9. Payout details after step-up; shown masked.
10. Prepare payout shows the full account (audited), recorded as paid.
11. The affiliate's private page on a phone: earnings and payout, no
    customer.
12. Application approved; overview; no server errors.

Local data note:
- B46 Tax Store (store 70) has a running "Umar Partners" program, test
  affiliates and orders from the browser check.

Not built (b57-affiliates.md §9):
- reseller program and platform package referrals (B58);
- tiered or per-product rates;
- automatic payouts; tax on commissions;
- translated rule summaries.

CI: run 38703771292 on b71b7a8 — success (backend, frontend, security, e2e),
verified 2026-10-15. The security job shows an "exit code 1" annotation from
the report-only build-tooling audit step (continue-on-error; the Tailwind 3
advisories listed in b52-security-review.md). It did not fail the job.

Next (on the owner's word): B58 — G20 part 2, the platform reseller
program (Module 28 §2.5, §46, §56–60; AFF-004).
