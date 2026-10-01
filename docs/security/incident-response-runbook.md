# Security Incident Response Runbook

Status: first version, 2026-09-30 (Phase B29, gap G2).
Sources: Module 32 §60–64 and §66, SRS SEC-015, owner decisions
(`docs/source/decisions/2026-09-30-project-decisions.txt` §4, §5).

This is the procedure to follow when something may have gone wrong with the
security of the platform: someone got into an account or a store's data, a
secret leaked, or the audit trail no longer verifies. It names the tools that
exist in the platform today. Where a tool does not exist yet, it says so.

## Before the first incident: fill these in

The specifications do not name people or contacts, and none are invented here.
The owner must fill in this table. Until then the runbook cannot be followed
out of hours.

| Role | Who | How to reach them |
|---|---|---|
| Incident lead (decides severity, runs the response) | _to be named_ | _to be filled_ |
| Technical responder (has production access) | _to be named_ | _to be filled_ |
| Communicator (talks to merchants and customers) | _to be named_ | _to be filled_ |
| Legal / contractual advice (notification duties) | _to be named_ | _to be filled_ |
| Hosting provider support | _to be filled_ | _to be filled_ |

Also to decide: where the incident record is kept (a private document or ticket
that only the people above can open — Module 32 §63.7).

## Severity

From Module 32 §61. The incident lead sets it and may change it as facts arrive.

| Severity | Examples | First response |
|---|---|---|
| **Critical** | One store can see another store's data. Production credentials or the application key are exposed. A payment or security control is compromised. Unauthorized platform-wide access. | Immediately, at any hour |
| **High** | Someone gained permissions they should not have. A significant part of one store's data is exposed. An API key or integration is being abused. | Same day |
| **Medium** | A limited weakness with no evidence of use. Repeated abuse (login attempts, scraping). Exposure of non-critical data. | Next working day |
| **Low** | A minor policy or control gap. A low-impact vulnerability report. | Planned work |

The response times are proposed defaults. The specifications set none.

## The eight steps (Module 32 §60)

### 1. Detection

An incident can start from:

- A critical alert. Owner decision §4: email is mandatory, WhatsApp is
  configurable. **Not built yet** — alert delivery is gap G4. Until then,
  detection depends on someone looking.
- The audit trail: `GET /api/v1/super-admin/audit-logs` (filters: `action`,
  `actor`, `actor_type`, `subject_type`, `request_id`, `from`, `to`).
  Actions worth watching: `auth.login.failed`, `auth.mfa.failed`,
  `auth.mfa.disabled`, `auth.password_confirmation.failed`,
  `super_admin.impersonation.started`, `super_admin.store.impersonated`.
- The audit chain check: `php artisan audit:verify`, or
  `GET /api/v1/super-admin/audit-logs/integrity`. A "broken" chain means
  entries were changed or removed outside the application.
- Platform health: `GET /api/v1/super-admin/infrastructure/health`,
  `/super-admin/store-health`, `/super-admin/monitoring/api-usage`,
  `/super-admin/payments/failures`.
- A report from a merchant, a customer or a researcher.
- The dependency scan failing in CI (`security` job).

Write down who noticed, when, and what they saw. Start the incident record.

### 2. Triage

Answer these, in the record:

1. What happened, as far as is known? What is only suspected?
2. Which stores, users and data are involved?
3. Is it still happening?
4. Severity (table above).

Every response carries an `X-Request-Id` header, and every audit entry stores
the request ID of the request that wrote it. If a reporter can give a request
ID, filter the audit log by `request_id` to see everything that request did.
The same ID is on the application's log lines for that request.

### 3. Containment

Stop the damage first. Prefer the smallest action that works, and preserve
evidence (step 4) before anything destructive.

| Situation | Action available today |
|---|---|
| A staff account is compromised | `POST /api/v1/super-admin/users/{user}/deactivate` (needs step-up). The account cannot sign in. |
| A team member of one store is compromised | The store owner suspends the member on the Team page (`POST /api/v1/team/members/{member}/suspend`). |
| An API key is leaked or abused | The store revokes the key (`DELETE /api/v1/developer/applications/{application}/keys/{apiKey}`). Platform staff suspend the whole application: `POST /api/v1/super-admin/developer/applications/{application}/suspend`. |
| Storefronts must be closed platform-wide | `platform.maintenance_mode` setting (`PUT /api/v1/super-admin/settings/platform.maintenance_mode`, needs step-up). It closes every storefront. It does not close the admin or the API. |
| A secret is exposed (`APP_KEY`, database password, provider credentials) | Rotate it in the hosting environment and redeploy. **Rotating `APP_KEY` invalidates every session, every encrypted setting, every MFA secret and every sealed notification body** — plan it with the technical responder; do not do it casually. |
| A vulnerable endpoint | Deploy a fix or block the path at the web server. There is no per-endpoint switch in the application. |
| An attacker's IP address | Block it at the web server or hosting firewall. The application has no IP block list. |

Not available yet: forcing sign-out of all of one user's sessions
(AUTH-005 is partial), and suspending a whole store from the Super Admin
surface as an incident action (store lifecycle is gap G14).

### 4. Evidence (Module 32 §63)

Before changing or deleting anything:

- Export the relevant audit entries (the listing above; note the request IDs).
- Run `php artisan audit:verify` and keep the output.
- Copy the application logs for the period (`storage/logs/`, and the hosting
  provider's web server logs).
- Note the deployed commit (`git rev-parse HEAD` on the server) and the time
  of the last deployment.
- Note the current platform settings (`GET /api/v1/super-admin/settings`)
  and, for a store, the history of a changed setting
  (`GET /api/v1/store/settings/{key}/history`).
- Take a platform backup: `POST /api/v1/super-admin/backups`.

Keep the evidence where only the incident team can read it. Do not paste
secrets, tokens or customer data into chat tools.

### 5. Eradication

Remove the cause: fix the code, remove the malicious account or key, rotate
every secret that may have been seen, update the vulnerable dependency
(`composer audit`, `npm audit`). Review what the affected account did
(audit log, filtered by `actor`).

### 6. Recovery

- Restore data only if it was changed or lost. A restore is requested by the
  store (`POST /api/v1/backups/{backup}/restore-request`) and authorized by
  platform staff (`POST /api/v1/super-admin/restore-jobs/{id}/authorize`).
  Both need step-up authentication. Targets from owner decision §5: RPO
  24 hours, RTO 4 hours. They are targets until a restore rehearsal has
  proven them (gap G4).
- Reactivate accounts (`.../users/{user}/reactivate`) after their passwords
  are reset and, for platform staff, MFA is set up again.
- Turn maintenance mode off, if it was turned on.

### 7. Validation

- `php artisan audit:verify` reports every chain as ok.
- The health endpoints are normal.
- The full test suite passes on the fixed code.
- The original way in no longer works. Test it.
- Watch the audit log for the same pattern for the following days.

### 8. Communication and review

During the incident, one person (the communicator) speaks for the platform.
Tell affected merchants what happened, what data was involved, what was done
and what they should do. Say what is known and what is not.

Whether and when the law or a contract requires notifying merchants,
customers or an authority is a legal decision (Module 32 §64). It is made by
the owner with legal advice, not by this runbook. To prepare that decision,
record:

- what data was affected, and whether it was read or only exposed;
- which stores and customers;
- when it started and when it was contained;
- which providers were involved.

Within a week of closing the incident, write a short review: timeline, cause,
what worked, what did not, and the changes to make. Add each change to the
gap matrix or the issue tracker with an owner.

## Break-glass access (Module 32 §66)

There is no separate emergency account in the application. If no platform
staff member can sign in (for example every authenticator is lost), access is
restored from the server by someone with production shell access, and that
action is itself an incident:

1. Record who did it, when and why in the incident record.
2. Prefer a recovery code. Each platform staff member must keep theirs.
3. As a last resort, clear the user's MFA on the server
   (`mfa_secret`, `mfa_confirmed_at`, the user's rows in
   `user_mfa_recovery_codes`) and have them enrol again at once. This is
   not audited by the application, so the record in step 1 is the audit.
4. Afterwards, rotate that user's password.

## What this runbook does not yet cover

- Alert delivery (email, WhatsApp) and scheduled backup checks: gap G4.
- Monitoring for unusual patterns (many failed logins from one address,
  unusual impersonation volume): Module 32 §58–59, not built.
- A tested restore: gap G4.
- A status page or a merchant notification template.
