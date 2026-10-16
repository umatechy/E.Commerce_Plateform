============================================================
PHASE B58 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B58 — The platform reseller program (gap G20, part 2):
- SRS AFF-001 to AFF-005 (AFF-004 reseller provisioning closes here).
- Module 28 §3.3, §5–6, §17, §25–31, §44, §46, §68–72, §92, §96, §120;
  Modules 03, 04, 29 stay the authorities for stores, packages and billing.

Owner request (2026-10-16): "good ab b58 py working start kr do".

Starting point:
- v1.1 at 448df00 (B57, CI green). Store affiliate program built; no
  reseller program.

Implementation Summary:
1. Tiers (Super Admin → Resellers → Tiers): partner discount for clients,
   first-invoice and renewal commission, renewal months (or as long as the
   client pays, or none), hold days, may create stores and how many, payout
   minimum. Umar Techy sets every number; none are seeded. Each store copies
   its reseller's terms the day it is brought.
2. Resellers: invited by Umar Techy, or apply on the public /partners page
   and are approved with a tier. The emailed invitation (48-char token,
   hashed, 14 days) is accepted with the account of the invited address;
   the partner portal needs two-step sign-in. Pause, resume, terminate.
3. Attribution: stores the reseller creates in the portal, clients who sign
   up through /register?partner=CODE, or stores Umar Techy assigns (with a
   reason; ends another reseller's). One open attribution per store.
4. Partner price: every Umar Techy invoice and plan-change preview of an
   attributed store uses the public price minus the store's discount,
   worked out on the server (invoice line "partner price").
5. Commissions (resellers:follow-up, hourly): one per paid invoice —
   initial, renewal or upgrade — within the renewal period; credit notes
   take back their share (before payout on the commission, after it by an
   adjustment); approval after the hold; Super Admin can reject one.
6. Payouts per currency: prepare (full account shown after step-up,
   audited), pay outside the platform, record the reference; cancel;
   adjustments.
7. Stores for clients: the portal wizard creates the store through Module 03
   with the client invited as owner, on the package's trial, optionally
   with the starter template; idempotent per key; tier limit. The reseller
   never joins the store.
8. Termination closes the portal and stops commissions on later invoices;
   earned commissions stay payable; clients keep their stores and partner
   price.
9. UI: Super Admin → Resellers (stats, Resellers with a full drawer, Tiers,
   Commissions, Payouts); Account → Partner portal (stats, sign-up link,
   Stores, Commissions, Payouts, Terms & payment, Create a store); public
   Partners page; Join page; partner named on the Register page.
10. Help: "Running the reseller program" (platform), "Your store and your
    Umar Techy partner" (store teams); release notes 1.1 updated.

Docs:
- docs/architecture/b58-resellers.md
- docs/security/b58-security-review.md
- gap matrix: Module 28 row, AFF-001–005 (done), G20 (built).

Found and fixed on the way:
- Inertia puts the #fragment back in the address bar when it starts, so the
  team invitation page (Phase G1) kept its token in the address bar and the
  browser history. `forgetHash()` now clears it on that page and on the
  partner join page.
- The shared token parser accepted only 64-character tokens; the join page
  has its own for the 48-character partner tokens.
- After creating a store, the portal's store list did not refresh.
- PayoutAccount (rules, masking, full account) is now shared by affiliates
  and resellers; B57 behaviour unchanged.
- B57 docs cited wrong section numbers for the reseller program; corrected.

Decisions to confirm (owner):
- The client pays the partner price; the reseller does not buy wholesale.
  Billing the reseller instead needs changes in billing (Module 29).
- A store keeps its partner price after its reseller is paused or
  terminated; it ends only when Umar Techy ends the attribution.
- Paused resellers keep earning on their stores; terminated ones earn
  nothing on invoices issued afterwards.
- A store assigned by Umar Techy earns only on invoices issued from then on.
- The reseller program is a platform feature, not a package feature (the
  `reseller.*` keys of §74 are not used).
- Capability `resellers`: platform admins and billing admins.

Not built (b58-resellers.md §1):
- sub-resellers and commission split (future in the blueprint);
- wholesale billing to the reseller;
- time-bound delegated access inside client stores (§30);
- help articles for partners (the help center has no partner audience).

Tests:
- New: ResellerProgramTest (8 tests); resellers.test.tsx (6).
- Adjusted: StepUpTest (11 new step-up routes).
- PHP suite: 1304 passed.
- Vitest: 267 passed.
- PHPStan, ESLint and TypeScript are clean. Production build OK.
- api:openapi --check is up to date; help:check is valid (64 articles).

Browser check (real Chromium; platform staff with MFA and step-up; a new
reseller; a phone-sized client): 8 of 8 passed, no 5xx.
1. A tier created in percent (stored 1000/2000/1000 bp) after step-up.
2. Invitation link shown once; only its hash stored.
3. The link opens the join page (token removed from the address bar);
   account created; sent to two-step sign-in; the portal refuses without it.
4. With two-step sign-in, the portal opens: terms, sign-up link, menu entry.
5. A store created for a client: created_via reseller, owner invitation to
   the client, no membership for the reseller.
6. A client signs up through the partner link on a phone: the page names
   the partner, the store counts for the reseller (source link).
7. The portal lists both stores (desktop and phone).
8. The Super Admin drawer shows both stores with their terms.

Local data note:
- Test resellers "Pixel Agency …", tiers "Silver …", stores "Rida Boutique …"
  and "Client Shop …" from the browser check.

CI: not yet run for this commit.

Next (on the owner's word): G22 feature flags (Module 35 foundation), the
next gap in the matrix order; G18 AI waits for the OpenAI key.
