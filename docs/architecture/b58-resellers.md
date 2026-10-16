# B58 — The platform reseller program (gap G20, part 2)

Sources: Module 28 §3.3 (PLATFORM_RESELLER), §5–6, §17, §25–31, §44, §46, §68–72,
§74, §92, §96, §120; SRS AFF-001–005; Module 03 (provisioning), Module 04
(packages), Module 29 (billing authority), Module 30 (Super Admin, step-up),
Module 32 (audit). B57 built the store affiliate program; B58 builds the
program Umar Techy runs itself: businesses that sell Umar Techy to their
clients.

## 1. Scope

| Module 28 area | B58 |
| --- | --- |
| Reseller accounts, separate from user identity (§5.3, §6) | ✅ `resellers` linked to one user account; states invited/pending/active/paused/rejected/terminated |
| Onboarding: application → approval → tier → portal (§92) | ✅ public `/partners` application; Super Admin invites or approves with a tier; emailed invitation (hashed token, 14 days); accept with the invited address |
| Tiers and pricing (§26–27, §46) | ✅ partner discount, first-invoice and renewal commission, renewal months, hold, may create stores, store limit, payout minimum; no values seeded |
| Partner price, worked out by the server (§26) | ✅ every Umar Techy invoice and plan-change preview of an attributed store |
| Store attribution (§17) | ✅ provisioned / sign-up link / manual; one open attribution per store; terms copied at attribution |
| Attribution conflicts (§68) | ✅ only Super Admin reassigns, with a reason; earlier commissions stay |
| Recurring commissions (§25) | ✅ initial, renewal, upgrade (proration) from paid invoices; renewal window; credit notes reversed |
| Payouts (§33–39) | ✅ per currency; manual, recorded with reference; full account after step-up, audited; adjustments |
| Reseller store provisioning (§28, §96, §120) | ✅ portal wizard; Module 03 creates the store; client invited as owner; idempotent per key |
| Ownership separate from reseller (§29) | ✅ the reseller never becomes a member of the store |
| Delegated access (§30) | 🟡 the portal shows status, package, trial, owner joined, commissions; no access inside the store (time-bound delegated admin access not built) |
| Reseller dashboard (§44) | ✅ Partner portal |
| Super Admin dashboard (§46) | ✅ Super Admin → Resellers |
| Termination (§72) | ✅ portal closed, no commissions on later invoices, earned ones payable, stores stay with owners |
| Sub-resellers, commission split (§31–32) | ❌ future (blueprint: "future") |
| Wholesale billing to the reseller (§26 "wholesale package pricing") | ❌ the client pays the partner price; billing the reseller instead needs Module 29 changes — owner decision |
| Package entitlement keys `reseller.*` (§74) | ❌ not used: resellers are platform partners, not store features |
| Reseller help articles for partners | 🟡 the help center has no partner audience; the portal and join pages explain the program |

## 2. Data

Migration `2030_04_02_000001_resellers.php` (platform tables, no tenant scope):

- `reseller_tiers`: terms in basis points; `recurring_months` null = as long as the client pays.
- `resellers`: user_id (unique), tier (null for an applicant), code (unique; the sign-up link), profile, status,
  invitation_token_hash + expiry, encrypted payout_details, approved/terminated times.
- `reseller_stores`: reseller, store, source, `provision_key` (unique per reseller), `terms` (copy), ended_at/reason.
  RETAIN in StoreDataMap.
- `reseller_commissions`: one per invoice (unique invoice_id), kind, status, base (invoice subtotal before tax),
  rate, amount, reversed, currency, hold_until, payout. RETAIN in StoreDataMap.
- `reseller_payouts`, `reseller_adjustments` (signed, automatic when created_by is null).

## 3. Attribution

- **Provisioned**: `ResellerProvisioning::provision()` → `StoreProvisioningService::forReseller()` (store,
  trial on the chosen package, owner invitation to the client, optional starter template) and, in the same
  transaction, `ResellerAttribution::attach(source: provisioned, provision_key)`. A retry with the same
  Idempotency-Key returns the first store; a concurrent duplicate hits the unique key and returns it too.
- **Link**: `GET /register?partner=CODE` remembers an active reseller in the server session;
  `AuthController::register` attaches the new store (failures are reported, never block sign-up).
- **Manual**: Super Admin assigns a store (id or slug) with a reason, ending any other open attribution.

## 4. Partner price

`InvoiceLedger::amountFor()` → `ResellerPricing::price(storeId, publicMinor)`: the public price minus the
attribution's `client_discount_bp` (discount rounded up, in the client's favour). Used by
`quoteNextPeriod()` (renewal invoices; the line says "partner price") and `PlanChangeService::preview()`
(proration). The discount stays while the attribution is open, also after the reseller is paused or
terminated; ending the attribution ends it from the next invoice.

## 5. Commissions (`resellers:follow-up`, hourly)

For each attribution: paid invoices with subtotal > 0, issued while the attribution was open and before
any termination, without a commission → one commission:

- kind `upgrade` for a proration invoice, `initial` for the first one, else `renewal`;
- renewals/upgrades only while `period_start` < attribution date + `recurring_months`;
- amount = `intdiv(subtotal × rate_bp, 10000)`; zero amounts are skipped;
- `hold_until` = paid_at + hold days → `approved` when it passes.

Credit notes (issued) on the invoice take back the same share — `reversed_minor` before payout, a negative
automatic adjustment after. Super Admin can reject a commission not yet in a payout, with a note.

## 6. Access

- Partner API `/api/v1/partner/*` (main auth group) + `reseller` middleware: the account's reseller must be
  active or paused, and **two-step sign-in must be on and passed in this session**. The reseller is taken
  from the account, never from the request.
- Super Admin `/api/v1/super-admin/resellers*`: capability `resellers` (platform_admin, billing_admin;
  super_admin); reads need `view`. Step-up on tiers, termination, store assignment/ending, payout details,
  payouts, pay-to and adjustments.
- Public: `/partners` (apply: throttle 3/10 min, honeypot `fax`, same answer for known addresses),
  `/partner/join` (token in `#fragment`, removed from the address bar; lookup/register by POST body).

## 7. UI

- `Partners/Apply` (public), `Partner/Join` (invitation), `Partner/Portal` (Account → Partner portal: stats,
  sign-up link, Stores, Commissions, Payouts, Terms & payment, Create a store for a client).
- `SuperAdmin/Resellers`: stats, Resellers (drawer: approve/decline, re-invite, pause/resume, edit,
  terminate with typed confirmation, balances per currency and payouts, adjustments, stores with assign/end,
  payout details), Tiers, Commissions, Payouts.
- Register page names the partner when opened through a partner link.

## 8. Found and fixed on the way

- `tokenFromHash` accepts only 64-character tokens (team invitations); the partner join page has its own
  parser for 48-character tokens.
- Inertia puts the `#fragment` back in the address bar when it starts, after the page's first effects; the
  team invitation page (Phase G1) and the partner join page therefore kept the token in the address bar and
  history. `forgetHash()` clears it again just after start.
- B57 docs cited wrong section numbers for the reseller program (§56–60); corrected.
