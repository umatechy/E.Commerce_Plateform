============================================================
PHASE B30 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B30 — Scheduled backups, restore rehearsal and critical alerts
(gap G4; Module 23 §9, §14–18, §29–30, §33–35, §47; SRS BKP-001,
BKP-003, BKP-007, HEALTH-005, TEST-011; owner decisions 2026-09-30 §4–5)

Starting point:
v1.1 at 654606b. B19 had manual backups, a restore request/authorize
flow and an expiry command. Nothing ran on a schedule, verification
compared sizes only, artifacts were plain SQL, no restore had ever been
rehearsed, and a failure told nobody.

Implementation Summary:

1. Scheduled backups
- A daily platform backup at 02:00 UTC; the backup of the 1st is the
  monthly one. One per day: a unique schedule key makes a second
  impossible, from an overlapping run, a second server or a retry.
- Retention tiers from settings: daily and manual 30 days, monthly 12
  months.
- Artifacts are gzip-compressed and, with BACKUP_ENCRYPTION_KEY set,
  encrypted (XChaCha20-Poly1305, streamed). Without a key they are
  stored unencrypted and shown as such.
- Verification reads the stored artifact back: size, SHA-256, full
  decode. A daily re-check finds a backup that went bad in storage and
  marks it failed.

2. Retention
- Deletes expired backups, artifact first. Keeps the newest verified
  platform backup, any backup a restore or rehearsal uses or waits for,
  and referenced safety backups. Deletes nothing while a restore or
  rehearsal runs. Safe to run twice or from two servers. One backup
  that cannot be deleted does not stop the others; it is audited,
  alerted and retried.

3. Restore
- Preflight reads the artifact (present, size, checksum).
- Authorization needs MFA, step-up (existing mechanism), the backup id
  typed back and an incident/change reference. One production restore
  at a time. A refusal changes nothing.
- A safety backup is taken and verified first; if it fails, no restore.
- The restore job decodes the artifact, records the real outcome and
  re-creates its own records after the import replaced the tables.
- No automatic rollback and no automatic maintenance mode: documented.

4. Restore rehearsal
- Imports a backup into a throw-away database on the same server,
  checks schema, migration history, every foreign key for orphans and
  every store-owned relationship for cross-store links, drops the
  database, and records checks and durations. Weekly, or on demand.

5. Critical alerts
- Through the existing outbox and notification pipeline: failed backup,
  storage failure, verification failure, damaged stored backup, no
  recent backup, repeated failure, cleanup failure, failed rehearsal,
  failed production restore.
- Email to `alerts.critical_email_recipients`, or to every active
  platform staff account while that is empty. WhatsApp is a setting; no
  provider exists yet, so such a message is recorded as failed.
- One alert per incident; platform records, invisible to stores.

6. Super Admin "Platform backups" page
- Status figures, backups, rehearsals and restore requests; back up
  now, check, rehearse, authorize a restore (reference, typed backup
  id, password and code).

Components reused (not duplicated):
B19 BackupService, RestoreService, state machine, storage and dump
abstractions, policies, resources; B17 typed settings (ConfigService,
SettingRegistry); B11 NotificationService, NotificationEventRouter,
DeliverNotificationJob, channel resolver; ADR-004 outbox; B22
AuditLogger; B29 step-up, MFA middleware, request IDs; B16 Super Admin
route groups; B21 store-health backup check (unchanged: it already
counts platform backups); the Laravel scheduler and queue.

Smallest corrections to existing code:
- notification_messages / notification_delivery_attempts: store_id may
  be null (a platform alert belongs to no store).
- TenantContext::asPlatform(): run one piece of platform-system work
  and put the context back.
- RecordsOutboxEvents::recordEventOnceFor(): for events that report a
  condition checked repeatedly.
- AuditLogger: request ID also outside web requests.
- Backup route binding accepts the public id the API returns.
- A store no longer sees the technical failure text of a backup.
- POST /api/v1/backups is rate limited (6 per hour).

Database:
2028_04_01_000001 — backups: retention_tier, schedule_key (unique),
compression, started_at, completed_at, last_checked_at, request_id;
backup_restore_jobs: mode, reference, report, duration_ms, request_id;
store_id nullable on the two notification tables.

Commands:
backups:run-scheduled, backups:verify, backups:monitor,
backups:rehearse, backups:expire (rewritten), backups:generate-key.

Settings added:
backup.monthly_retention_months, backup.rehearsal_enabled,
alerts.critical_email_recipients, alerts.whatsapp_enabled,
alerts.whatsapp_recipients.

Audit actions:
backup.created, backup.completed, backup.verified, backup.failed,
backup.verification_failed, backup.expired, backup.deleted,
backup.overdue, backup.repeated_failure,
backup.retention_cleanup_failed, restore.preflight_failed,
restore.authorized, restore.started, restore.completed, restore.failed,
restore.rehearsal_started, restore.rehearsal_completed,
restore.rehearsal_failed, alert.raised.

Runtime verification — EXECUTED (local MySQL 8.0.40, Windows,
development database of 4.9 MB / 90 tables, 2026-10-01):
- Real scheduled backup: mysqldump, gzip, stored, verified. 38–45 KB,
  about 1.5 s.
- Second run the same day: no second backup.
- backups:verify --deep: intact.
- Real restore rehearsal: 9.0 s total (import 6.8 s); 90 tables, 101
  migrations, 173 foreign keys without orphans, 65 store-owned
  relationships without a cross-store link; rehearsal database dropped.
- The same from the browser (Super Admin page): back up now, check,
  rehearse.
- A real failure (mysqldump could not connect from the dev web server)
  was recorded as failed, and after the outbox ran, the alert email was
  produced (mail driver: log).
- Automated, real MySQL (MysqlRehearsalTest, also in CI): encrypted
  backup of the test database and its rehearsal.

Runtime verification — NOT EXECUTED — ENVIRONMENT LIMITATION:
- A production restore against a live database. Not run: it is
  destructive and there is no disposable production-like environment.
  The import path is the one the rehearsal runs.
- S3-compatible backup storage: no such storage is configured.
- Real email delivery (SMTP) and WhatsApp: no provider credentials.
  The pipeline was run to the mail log driver.
- Media/object backup: NOT IMPLEMENTED.
- A queue worker and cron: locally the queue is `sync` and the
  scheduler commands were run by hand.
- A least-privilege database account: runs used an administrative one.
- A database of production size: RTO has no meaningful measurement.

RPO / RTO (targets: 24 h / 4 h):
Not claimed as met. RPO by design is one day; `backups:monitor` alerts
past it. RTO: only the 9.0 s rehearsal of a 4.9 MB development
database. Each weekly rehearsal records its duration.

Findings and known limitations:
1. A store's "backup" is a full dump of the shared database, recorded
   against that store. It is never downloadable and only platform staff
   can restore, but it costs a full dump (now rate limited) and the
   artifact holds every store's data. A true per-store backup and
   restore does not exist.
2. No automatic maintenance mode and no automatic rollback around a
   production restore. The runbook has the manual steps.
3. Backups live on one disk. No off-site copy, no immutability.
4. Without BACKUP_ENCRYPTION_KEY, backups are unencrypted. Nothing
   forces a key in production; the page warns.
5. Key rotation: one key at a time; old backups need the old key.
6. Security events still raise no alert (HEALTH-005 partly open).
7. The 90-day closed-store retention is not enforceable before G14.
8. The older tests still run with MFA and step-up off (B29 finding 1);
   the new restore tests switch them on for the restore route.
9. Local development: MySQL, the Laravel server and Vite were started
   by hand during this phase; the harness stopped its own copies after
   two hours.

Tests (2026-10-01):
PHP: 1011 passed, 0 failed, 0 skipped (baseline 930; 81 added).
PHPStan: no errors. Vitest: 46 passed (41 before). ESLint and the
production build pass. composer audit and npm audit (shipped
packages): no advisories. New files:
ScheduledBackupTest, BackupArtifactCodecTest,
BackupIntegrityAndRetentionTest, RestoreSafetyTest,
RestoreRehearsalTest, MysqlRehearsalTest, CriticalAlertTest,
BackupAccessBoundaryTest, backups.test.ts.

Requirements closed:
BKP-001, BKP-007, TEST-011. HEALTH-005 for backup and recovery
conditions.

Next:
Per the gap matrix order: G3 (tax, needs the owner's rules) and
G9–G10 (payment and messaging providers, need provider decisions and
credentials); otherwise G6 (admin UI) with G7, G8 and G15.
