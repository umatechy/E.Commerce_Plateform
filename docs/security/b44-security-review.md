# B44 security review — store onboarding

| Area | Risk | Control | Test |
|---|---|---|---|
| Who may create stores for others | A store owner or staff provisioning stores | `can:super-admin.platform` + privileged MFA + **step-up**; audited `store.provisioned` | staff-create test (owner → 403); StepUpTest route list |
| Double creation | Retries / double clicks making two stores | `Idempotency-Key` per staff member, 24 h | staff-create test |
| Owner role escalation | Inside a store, someone grants "owner" | `invite()` still refuses the owner role; `inviteOwner()` only from provisioning and the Super Admin route; refused once an owner exists | `owner_exists` assertion |
| Invitation link | Token leak or reuse | 64-char token, stored hashed, link in `#fragment`, sealed body wiped after sending; a new invitation revokes the old one; unusable links answer the same 404 | replace-link test |
| Account takeover via invitation | Accepting for an existing account without signing in | existing accept rules: existing accounts must sign in as that address | existing-account acceptance |
| Owner security | New owner without second factor | owner MFA stays mandatory (browser check: enrolment before the admin) | browser check 3 |
| Tenant isolation | Invitation or email written to the wrong store | `TenantContext::asStore()` restores the previous context in `finally` | staff-create test (store ids) |
| Half-made stores | Partial provisioning | one transaction for store, membership/invitation, subscription, audit and outbox | — |
| Unpaid launch | Going live before paying when Umar Techy requires payment | server-side launch check (not UI); first invoice issued in a transaction (ADR-004 outbox) — a bug found by the test | live-after-payment test |
| Input | Bad business info, unknown categories/packages | fixed category list; email/phone/country/length validation; trial package must be active | settings test |
| Sign-up closed | Bypassing the closed page | the API refuses (`signup_closed`), not only the page | sign-up-closed test |

No new secrets or providers; no change to authentication strength.
