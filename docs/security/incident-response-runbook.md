# Security Incident Response Runbook

Status: 2026-10-01 (Phase B29, gap G2; roles and emergency MFA reset per the owner's decisions of 2026-10-01; backup and recovery procedures added in Phase B30, gap G4).
Sources: Module 32 §60–64 and §66, SRS SEC-015, owner decisions
(`docs/source/decisions/2026-09-30-project-decisions.txt` §4, §5).

This is the procedure to follow when something may have gone wrong with the
security of the platform: someone got into an account or a store's data, a
secret leaked, or the audit trail no longer verifies. It names the tools that
exist in the platform today. Where a tool does not exist yet, it says so.

## Roles and contacts

The roles are fixed. The people and their contacts are **TBD**: they have not
been provided, and none are invented here (owner decision, 2026-10-01). Fill
them in when they are explicitly provided. Until then the runbook cannot be
followed out of hours.

| Role | Responsibility | Who | Contact |
|---|---|---|---|
| Incident Commander | Declares the incident, sets the severity, runs the response, decides when it is closed | TBD | TBD |
| Technical/Infrastructure Lead | Production access; containment and fixes on servers, deployments and secrets | TBD | TBD |
| Security Lead | Investigation, evidence, audit trail; authorizes emergency procedures such as the MFA reset | TBD | TBD |
| Backup/Recovery Lead | Backups, restores, and proving that restored data is right | TBD | TBD |
| Communications/Notification Lead | Speaks for the platform to merchants and customers; prepares notifications | TBD | TBD |

One person may hold more than one role. The Security Lead and the person who
runs an emergency procedure on the server should be two different people.

Also TBD: where the incident record is kept (a private document or ticket that
only the people in these roles can open — Module 32 §63.7).

## Severity

From Module 32 §61. The Incident Commander sets it and may change it as facts arrive.

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

- A critical alert by email (Phase B30): a failed or damaged backup, no
  recent backup, a failed rehearsal or restore, a failed cleanup. Recipients
  are the platform setting `alerts.critical_email_recipients`, or every
  active platform staff account while that is empty. WhatsApp is optional
  and has no provider yet. **Security events do not alert yet** (failed
  sign-ins, MFA changes): for those, detection still depends on someone
  looking.
- The audit trail: `GET /api/v1/super-admin/audit-logs` (filters: `action`,
  `actor`, `actor_type`, `subject_type`, `request_id`, `from`, `to`).
  Actions worth watching: `auth.login.failed`, `auth.mfa.failed`,
  `auth.mfa.disabled`, `auth.mfa.emergency_reset`,
  `auth.password_confirmation.failed`,
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
| A secret is exposed (`APP_KEY`, database password, provider credentials) | Rotate it in the hosting environment and redeploy. **Rotating `APP_KEY` invalidates every session, every encrypted setting, every MFA secret and every sealed notification body** — plan it with the Technical/Infrastructure Lead; do not do it casually. |
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
  Both need step-up authentication, and the authorization needs the backup
  id typed back and a reference. Follow "Production restore" below. Targets
  from owner decision §5: RPO 24 hours, RTO 4 hours. They are targets: the
  weekly rehearsal records how long a restore of the current backup takes.
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

During the incident, one person (the Communications/Notification Lead) speaks for the platform.
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

## Backup and recovery incidents

Added in Phase B30 (gap G4). The Backup/Recovery Lead leads these; the
Incident Commander decides the severity. Operations detail:
`docs/operations/backup-and-restore.md`. Status at any time: the Super Admin
"Platform backups" page, or `GET /api/v1/super-admin/backups/summary`.

Every alert email names the backup or restore, the reason, the request ID
and one of the procedures below.

### Backup failure

Alert: "Scheduled backup failed", "Backup failed", "Backup storage failure",
"No recent backup", "Backup cleanup failed". Severity: Medium for one failed
backup while yesterday's is intact; High when there is no verified backup
within 24 hours.

1. Read the reason in the alert or on the Backups page. The stage tells where
   it broke: `dump` (database or `mysqldump`), `encode` (compression or the
   encryption key), `store` (backup storage), `verify` (the stored copy).
2. Fix the cause. Common ones: the disk is full, the storage credentials
   changed, `mysqldump` is not on PATH, the queue worker or the scheduler
   stopped ("No recent backup" with no failed backup means nothing ran).
3. Take a backup now ("Back up now", or `POST /api/v1/super-admin/backups`).
4. Confirm it is `verified`. Close the record.

### Repeated backup failure

Alert: "Backups keep failing" (two scheduled backups in a row). Severity:
High. The platform is losing its recovery point.

1. As above, today, not tomorrow.
2. Until a backup is verified, avoid risky changes (deployments with
   migrations, bulk imports).
3. If the cause is the storage itself, point `BACKUP_DISK` at another private
   disk, take a backup, then repair the first.

### Backup corruption

Alert: "Stored backup is damaged or missing", "Backup verification failed".
Severity: High. Critical if it is the only backup, or if tampering is
suspected.

1. The backup is already marked failed and cannot be chosen for a restore.
2. Take a new backup now and confirm it is `verified`.
3. Run `php artisan backups:verify --all --deep` to check every other backup.
4. Decide: storage fault or tampering? Look for who can write to the backup
   disk and whether anything else changed. If tampering is possible, treat
   it as "Suspected backup exposure" too.
5. Run a rehearsal of the new backup.

### Restore rehearsal failure

Alert: "Restore rehearsal failed". Severity: High. Live data was not touched,
but a backup that cannot be restored is not a backup.

1. Read the failed checks on the Backups page (the rehearsal's report).
2. "could not be created" / "access denied": the database account lacks the
   rehearsal grant (operations document, "Setting it up"). An environment
   fault, not a bad backup. Fix it and rehearse again.
3. Import errors, missing core tables, orphaned rows or rows linked across
   stores: the backup, or the data it was taken from, is wrong. Take a new
   backup and rehearse it. If the new one fails the same checks, the live
   data has the problem: that is a data-integrity incident, Critical.
4. If a rehearsal database was left behind (the report says so), drop it by
   hand: it is a full copy of the data.

### Production restore

Severity: Critical by definition. It replaces every store's data.

1. The Incident Commander decides that a restore is the right recovery, and
   to which backup. Everything written after that backup will be lost.
   Record the decision and the incident reference.
2. The Communications/Notification Lead tells merchants that the platform is
   going into maintenance.
3. The Technical/Infrastructure Lead: `php artisan down`, stop the queue
   workers and the scheduler.
4. The Backup/Recovery Lead rehearses the chosen backup first if there is
   time (`backups:rehearse --backup=ID`).
5. A second person authorizes the restore on the Backups page: the reference,
   the backup id typed back, the password and two-step code.
6. The safety backup is taken automatically. Note its id.
7. When the restore job is `completed`: `php artisan migrate`,
   `php artisan audit:verify`, check the health endpoints, spot-check a store.
8. Start the workers, `php artisan up`, tell merchants.
9. If the job is `failed` ("PRODUCTION RESTORE FAILED"): **do not retry
   blindly and do not take the platform out of maintenance.** The database
   may be half restored. Request and authorize a restore of the safety backup
   from step 6. There is no automatic rollback.

### Suspected backup exposure

Someone who should not have it may have a backup file. A backup holds every
store's customers, orders and password hashes. Severity: Critical when the
backup was unencrypted, High when it was encrypted and the key is safe.

1. Establish which backups, and whether they were encrypted (`is_encrypted`).
2. Cut off the access: rotate the storage credentials, remove public access.
3. If the backup was unencrypted, or the key may be known too: this is a data
   breach of every store. Go to "Communication and review" and the legal
   decision; plan a forced password reset.
4. If it was encrypted and the key is safe: rotate the key anyway (next
   procedure), and record why exposure of contents is not assumed.
5. Preserve the storage access logs.

### Storage credential compromise

1. Rotate the storage credentials at the provider and in the environment.
2. Review the provider's access log for reads, writes and deletes.
3. Run `php artisan backups:verify --all --deep`: a changed or deleted
   backup shows up as failed.
4. Take a new backup.
5. If backups were read: "Suspected backup exposure".
6. Rotating the backup encryption key: generate a new one
   (`backups:generate-key`) and set it. **Keep the old key** until every
   backup made with it has expired; the application reads with one key at a
   time, so restoring an old backup means setting the old key for that
   restore. There is no re-encryption of existing backups.

### Cross-tenant backup exposure

A store saw, or could act on, another store's backup or a platform backup.
Severity: Critical (Module 32 §61).

1. Contain: if it is an application fault, disable the route at the web
   server.
2. What the application is built to guarantee, and what to check against:
   a store lists only its own backups; another store's backup or a platform
   backup answers "not found" by any id; no response contains a storage
   path; no endpoint downloads an artifact; alerts are platform records.
   `BackupAccessBoundaryTest` asserts all of it.
3. Find what was exposed: metadata only (ids, sizes, dates), or contents. The
   API has never been able to return contents.
4. Search the audit log by `request_id` for the requests involved, and for
   `restore.preflight_failed` and restore requests by that store.
5. Notify the affected stores according to the legal decision.

## Emergency MFA reset

For one case only: a staff member has lost the authenticator device **and**
every recovery code, so they cannot sign in. (Owner decision, 2026-10-01;
Module 32 §8.3, §66.)

There is no way to do this through the application. No screen and no API
endpoint can turn off another account's MFA, and no user, including a Super
Admin, can do it for someone else. The reset is a console command on the
server. Using it is an incident and is recorded as one.

It applies to platform staff. Store Owners must also have MFA, so the same
procedure is the only recovery for an owner who has lost both; the command
accepts any staff account.

**Who.** The Security Lead authorizes it. The Technical/Infrastructure Lead,
or another person with production shell access, runs it. These should be two
different people.

**Procedure.**

1. Open an incident record. Note who asked, when, and through which channel.
2. Verify the person's identity through a channel that does not depend on the
   locked account's email alone (a call to a known number, in person, or a
   second staff member who knows them). A request that arrives only by email
   or chat is not enough: this procedure is exactly what an attacker who has
   the password would ask for.
3. Check first whether a recovery code exists. If one does, use it. Stop here.
4. The Security Lead approves the reset in the incident record.
5. On the production server, as the deploy user:

   ```
   php artisan mfa:emergency-reset person@example.com \
       --operator="Your Name" \
       --reason="Authenticator and recovery codes lost. Incident INC-0000"
   ```

   The command shows the account and asks you to type its email again. It
   refuses without an operator, without a reason of at least 10 characters,
   or when the confirmation does not match. Scripts must pass
   `--confirm=person@example.com`.
6. Have the person set a new password (password reset).
7. Have the person sign in and enrol MFA again at once, while you are still
   in contact. Until they do, a platform staff account cannot open any Super
   Admin route and a Store Owner cannot open the Store Admin: both are sent
   to enrollment.
8. Confirm the audit entry exists (below) and close the incident record.

**What the command does.**

- Destroys the account's MFA secret and all its recovery codes. It never
  reads, prints or logs them.
- Revokes remembered sign-ins (the remember-me token is replaced).
- Writes a high-severity audit entry in the platform chain:
  action `auth.mfa.emergency_reset`, with `severity: high`, the operator, the
  reason, the account type and the account as the subject. Find it with
  `GET /api/v1/super-admin/audit-logs?action=auth.mfa.emergency_reset`.
- Writes a `critical` line to the application log.

**What it does not do.**

- It does not end sessions that are already signed in. If the account may be
  in the wrong hands, deactivate it first
  (`POST /api/v1/super-admin/users/{user}/deactivate`) and reactivate it
  after step 7.
- It does not change the password.
- It does not send an alert. Critical alerts exist for backups and restores
  (Phase B30) but not yet for security events; someone must look at the
  audit log.

**Break-glass (Module 32 §66).** There is no separate emergency account in
the application. If no platform staff member can sign in at all, this
procedure, run from the server for one named account, is the break-glass
path. It must not become routine: every use is an incident with a record.

## What this runbook does not yet cover

- Alerts on security events (many failed sign-ins, MFA disabled or reset on a
  privileged account). Backup and restore alerts exist since Phase B30.
- Monitoring for unusual patterns (many failed logins from one address,
  unusual impersonation volume): Module 32 §58–59, not built.
- A production restore that has actually been run. The rehearsal runs weekly;
  the destructive restore itself has not been executed.
- Media/object backups, off-site copies and immutable backups.
- A status page or a merchant notification template.
