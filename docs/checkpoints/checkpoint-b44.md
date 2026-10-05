============================================================
PHASE B44 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B44 — Store onboarding, both creation models (gap G23, owner decision 13;
Module 03 §5–8, §14, §23–27, §38, §55–58; Module 04 §14–17)

Owner request (2026-10-04): option 3 — "dono rakhen"; then the ordered
list, one phase at a time, auditing existing code first.

Starting point:
v1.1 at 5e8b667 (decision 13 recorded). Stores were created inline in
registration and again in the demo command; no staff creation; launch
during the trial; 6-item launch checklist.

Implementation Summary:
1. StoreProvisioningService: the one creation path (self-service and
   platform-assisted); registration and demo:store now use it.
2. Stores: business_category, created_via, created_by_user_id,
   activated_at.
3. Platform settings: sign-up open, launch requires payment, trial package
   and days; store business information settings.
4. Super Admin: create a store for a customer (step-up, idempotent),
   owner invitation (new or existing account), send again; store list with
   stage, package, sells, owner, creator, filters; store page stage and
   setup progress.
5. StoreLifecycle stage derived from store + subscription (no duplicated
   states; decision documented in b44-store-onboarding.md §3).
6. Setup checklist: three groups, required/optional, progress %, blocking
   steps, package-aware, first payment when required; "Get my first
   invoice".
7. Sign-up page: "What will you sell?"; closed state.

Found and fixed: the first-invoice call must run in a transaction (outbox
rule ADR-004) — found by its test; demo command duplicated store creation
— now uses the service.

Tests (2026-10-05):
PHP: 1148 passed (StoreOnboardingTest 7; StepUpTest and launch test
updated for the new step-up routes and the business-info requirement).
PHPStan: no errors.
Vitest: 197 passed (onboarding.test.tsx 3).
ESLint, TypeScript: clean. Production build: passes.

Browser verification — EXECUTED (Chromium, local server, MFA and step-up
on, production build): 7 of 7, no console errors.
1. Platform staff sign in with two-step sign-in.
2. Create store for a customer (Food, Basic, 7 days): store page shows
   "Waiting for the owner" and the invitation.
3. The customer opens the emailed link, creates the account, turns on
   two-step sign-in.
4. Owner dashboard: 33% done, three groups, 2 required steps; Super Admin
   stage "Setting up".
5. Launch-after-payment on: "Your first payment" required with "Get my
   first invoice"; setting restored.
6. Sign-up closed: contact message; reopened: "What will you sell?".
7. Stores list row: stage, package, owner; 375 px phone fits.

Not built (b44-store-onboarding.md §6): starter templates (B45); online
first payment (B48); trial abuse controls; ownership transfer; closure
(B51).

CI: the first run on bb3bf3e failed in one test (the sign-up page test needed withoutVite(); CI has no frontend build) — fixed in c21c382; run on c21c382 — success, verified 2026-10-05.

Next (on the owner's word): B45 — business-category starter templates.
