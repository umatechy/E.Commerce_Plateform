# B58 security review — platform reseller program

Scope: `app/Domain/Resellers`, `EnsureActiveReseller`, partner price in billing, the partner
portal, the join and application pages, Super Admin → Resellers.

| Threat | Control | Test |
| --- | --- | --- |
| A reseller reaches another reseller's data | The reseller comes from the signed-in account (middleware), never from the request; all queries filter by it | provisioning test (another reseller sees 0 stores) |
| A reseller reaches a client store's data (§30) | The reseller is never a store member; the portal returns only name, state, package, trial, owner joined, commissions | provisioning test (no store_user row), signup test |
| Portal without a second factor | `reseller` middleware: MFA enabled **and** verified in the session, else 403 | onboarding test (enrollment and verification refusals) |
| Terminated reseller keeps access | Middleware admits only active/paused; termination clears any invitation | termination test |
| Invitation link stolen or reused | 48-char random token, only SHA-256 stored, 14 days, one use, emailed as a secret variable; accepted only by the account of the invited address; token kept in `#fragment` and removed from the address bar | onboarding test (email_mismatch, reuse 404, [hidden] in notifications); browser check |
| Arbitrary reseller price (§26) | Price computed on the server from the attribution's copied terms; no price field in any request | price test (2700 on 3000; plan-change preview 5400) |
| Tier edits changing past deals | Terms copied into each attribution; commissions keep rate and base | signup test (tier edit leaves 10%) |
| Double store creation (§120) | Idempotency-Key required; unique (reseller, provision_key); concurrent duplicate returns the first | provisioning test |
| Commission on unpaid, refunded or post-termination invoices | Only paid invoices; credit notes reversed proportionally; invoices after termination or attribution end skipped; one per invoice (unique) | price, payout and termination tests |
| Double payout | One open payout per reseller, row locks, idempotency keys, `payout_not_pending` | payout test |
| Payout account exposure | Encrypted; masked everywhere; full account only after step-up for capability holders, audited each time | payout test (2 audited reveals) |
| Unauthorized platform staff | Capability `resellers` on every change (reads need `view`); step-up on terms, termination, assignment, payouts, adjustments, payout details | capability test; StepUpTest |
| Application spam / address probing | Throttle 3 per 10 min, honeypot, same 202 answer | application test |
| Sign-up blocked by attribution errors | Attribution after store creation in try/catch with report | design |

Audit events (platform chain): tier created/updated, reseller invited/applied/approved/rejected/
reinvited/joined/paused/resumed/terminated/updated, payout details changed/revealed, store attributed/
unattributed, commission created/rejected, payout created/paid/cancelled, adjustment created.

Residual risks:

- A reseller could set up fake client stores to earn the first-invoice commission; commissions need a
  paid invoice, the hold period gives time for review, and Umar Techy can reject a commission.
- Payouts are recorded, not verified: the reference is what staff type.
- The partner discount is visible to the client (invoice line "partner price"); amounts of commissions
  are not.
