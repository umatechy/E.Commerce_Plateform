============================================================
PHASE B32 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B32 — Customer management (gap G7; Module 10 §9–10, §13, §24–33,
§47–58, §67) and the remaining admin gaps of B31 (§20 of the G6
report): payment for staff orders (Module 09 §70), promotion target
names, package contents editing (Module 04 §63), platform settings
history (Module 33 §32), theme draft preview (Module 17 §19),
breadcrumbs on the last pages without them.

Starting point:
v1.1 at f66f725 (CI green). Customers existed as accounts with an
address book and privacy export/erase; the admin had only customer
figures, no list or detail.

Implementation Summary:

1. Customer data (migration 2028_05_01_000001)
Status (active / blocked / archived) with reason and time, source
(registered / staff / import), one group per customer, tags, staff
notes, email verification rows (token hash only). Four permissions
(customers.view / manage / export / import), granted to existing
system roles by migration 2028_05_01_000002.

2. Staff customer API and screens
List with figures (orders, spent, last order), search and nine filters
in the URL; detail with profile, figures, orders, addresses, group,
tags, notes, activity timeline, possible-duplicate warning, block /
archive / restore with reason, privacy export and erase (now with
step-up); groups and tags page; add a customer; CSV import (preview,
then confirm; nothing written before); CSV export of the filtered list.

3. Block effects (Module 10 §31)
No sign-in (sessions revoked, old tokens refused), no checkout (signed
in or as a guest with the address), no staff order, no new account with
the address, no marketing. Archived: no sign-in, no marketing.

4. Email verification and guest conversion (§9, §13)
Registering sends a one-time link (24 h); the account dashboard can ask
again; the storefront page /account/verify-email confirms it. Earlier
guest orders with the exact address join the account after the link or
a password reset — never by name.

5. Admin gaps of B31
- Create order: customer picker or guest, and the payment method (cash
  on delivery / bank transfer as the package allows); the payment is
  created with the order, idempotently. Order detail links the customer.
- Promotions show target names.
- Packages: contents editor (features on/off, limits), reason required,
  step-up, audited before/after = entitlement history.
- Platform settings: history and rollback (step-up).
- Theme: "Preview draft" opens the storefront with the saved draft for
  30 minutes, with a banner and "End preview"; not indexed.
- Breadcrumbs on Security, Support, Help from the platform, platform
  Backups and platform Support.
- Segment preview links to customers.

Defects found and fixed (details: docs/development/b32-inspection-findings.md):
1. Store settings history/rollback reached platform settings (security).
2. A staff order accepted another store's customer id (security).
3. Erasure left the customer's address book; export left it out.
4. The staff order response had no customer.
5. A blocked customer could get round the block with a new account.
6. New rate limits shared one counter (export answered 429 after a few
   searches) — found in the browser check.
7. Customer erasure had no step-up.

Reused (not duplicated):
Customer model, CustomerRegistration, password reset, CustomerDataService
(Module 32), AuditLogger, NotificationService (secret variables),
PaymentService::createForOrder and manual confirmation, ConfigService
revisions, EntitlementService cache keys, ThemeService drafts,
StorefrontExperience, the B31 UI kit, API layer, step-up dialog and
SettingsEditor.

Tests (2026-10-03):
PHP: 1056 passed, 0 failed (1027 at B31; added:
tests/Feature/Customers/CustomerAdminTest (10), CustomerStandingTest (5),
CustomerImportExportTest (5), AdminGapsTest (7), one campaign test;
updated: StepUpTest route list, CustomerPrivacyTest erase summary).
PHPStan: no errors.
Vitest: 134 passed (126 at B31; 8 added in resources/js/test/customers.test.tsx).
ESLint, TypeScript: clean. Production build: passes.
composer audit and npm audit (shipped packages): no advisories. The
dev-tooling audit (Vite/Vitest/Tailwind 3) is report-only in CI as
recorded in docs/security/b29-security-baseline.md.

Browser verification — EXECUTED (Chromium via Playwright, local server,
MySQL 8.0, MFA and step-up ON, production build): 17 of 17 checks.
Owner: register and enrol MFA (Security breadcrumbs); product and stock;
empty customer list, add a customer, duplicate email refused on its
field; groups page with breadcrumbs; detail: group, tag, note, activity;
block needs a reason, banner, no "Create order", unblock; import with
problem rows, then confirm; export follows the filter; staff order for
the customer with cash on delivery, payment listed, customer linked;
customer page lists the order; theme preview opens with banner and
noindex, End preview removes it; Support breadcrumbs; storefront wrong
confirmation link explained.
Platform staff: sign-in with code; package contents editor (changes
counted, save needs a reason — not saved, shared local data); platform
settings history; breadcrumbs on platform Backups and Support.
The only console errors were the two deliberate 422 answers.

NOT EXECUTED — ENVIRONMENT LIMITATION:
- Email delivery (mail driver `log`): the confirmation email and
  campaign emails were not followed to an inbox.
- Saving package contents in the browser (shared local packages); the
  save, cache drop and history are covered by AdminGapsTest.
- Screen readers; browsers other than Chromium.

Known limitations:
1. Customer merge is reserved (Module 10 §56); duplicates are warnings.
2. Package limits for customer features: owner decision needed (Module
   04 §8 has no mapping; nothing invented).
3. Customer groups do not yet drive prices or promotion eligibility
   (Module 14 §17–20).
4. Still open from B31: variants without their own price cannot be
   ordered; no edit/delete for attributes, shipping zones/methods,
   segments; no delete for warehouses and promotions; dashboard sums
   ignore currency.
5. The browser checks are scripts outside the repository (gap G13).

Requirements closed:
Gap G7. Items 1, 2, 4 (package entitlements, platform settings history),
5 and 7 of the B31 known limitations.

Next:
G8 (returns, exchanges, refund flow — Module 09 §45–54, Module 13 §70),
then G15 (catalog depth). G3 (tax) and G9–G10 (providers) still wait for
the owner's rules and credentials.

============================================================
ADDENDUM — owner decisions of 2026-10-03
============================================================

1. Customer features by package (Module 10 §87): groups, tags, CSV
   import/export and merge are Business and Premium
   (`customers.advanced`); Basic keeps the rest. No "Maximum
   Customers" limit. Seeder and migration 2028_05_01_000003; enforced
   in the API; the screens say what needs a bigger package. Existing
   groups and tags are never deleted on Basic.
2. Customer merge (Module 10 §56): built. Explicit (chosen target,
   typed email, reason, step-up), same store only, audited, with a
   `customer_merges` record; refuses what would lose a sign-in or a
   block. Migration 2028_05_01_000004.

Tests: PHP 1061 passed (CustomerMergeAndPackageTest 5 added; four
customer tests now give their store the Business-level feature);
Vitest 137 passed (3 added). PHPStan, ESLint, TypeScript: clean.
Browser (Chromium, local): 5 of 5 — Basic hides groups/tags/import/
export and the API refuses (403); Business opens them; a duplicate with
the same phone merged into the customer who stays; the merged record
points to it and offers no changes.

Known limitation 1 and 2 above are closed by this addendum.
