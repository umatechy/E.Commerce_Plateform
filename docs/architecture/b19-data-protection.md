# Phase B19 — Data Protection Model (Module 23, Phase 35)

## What Data Is Protected

A backup is a full-database snapshot (`mysqldump --single-transaction`)
covering every tenant-owned table (orders, customers, products, inventory,
etc.) and every platform-global table (Package/Theme catalogs, platform
settings) — see `docs/development/b19-inspection-findings.md`'s data
classification table for the complete category-by-category breakdown of
what is and is not included.

## Where Backup Artifacts Live

Via `BackupStorageAdapter` → `LocalBackupStorageAdapter`, backed by Laravel's
`local` disk (the same disk B12's report exports already use) at a server-
generated, opaque path (`backups/{ulid}.sql`) — never a client-supplied path,
never a path that encodes the store id or any other predictable information.
A future S3-compatible adapter is a container-binding change only.

## How Tenant Isolation Works

`Backup`/`BackupRestoreJob` deliberately do NOT use `BelongsToTenant` (a
backup legitimately spans both Platform and Store scope, the same reasoning
B17's `SettingRevision` established). Every controller applies an EXPLICIT
`store_id` check before returning or acting on a `Backup` row — proven by
`BackupTenantIsolationTest`. A client-supplied `store_id`/`tenant_id` is
never trusted anywhere in this domain; the acting user's own resolved
`TenantContext` is the sole source of truth for "which store's backups can I
see."

## How Backup Artifacts Are Encrypted

**Not yet encrypted at rest in B19.** `Backup.is_encrypted` exists as a
schema field and `BackupService`'s manifest always records `encrypted:
false` honestly — no fake encryption flag is set. Module 23 §14 requires
encryption "where required" and explicitly forbids storing keys inside the
artifact itself; implementing this correctly (via Laravel's real `Crypt`
facade, streaming-encrypting a potentially multi-gigabyte SQL file rather
than loading it entirely into memory) is named as a concrete, real follow-up
requiring its own careful design — not fabricated as complete when it is
not.

## How Integrity Is Verified

SHA-256 checksum computed at dump time, then INDEPENDENTLY re-verified
against the stored artifact's actual size in `BackupService::verify()` — a
mismatch fails the backup rather than silently accepting a corrupt file.

## How Retention Works

`backup.retention_days` (B17 platform setting, default 30) sets each
backup's `expires_at` at creation time. The daily `backups:expire` command
(existing scheduler) deletes only `verified`/`failed` backups past their
expiry, structurally protecting `restoring` backups and any backup
referenced as a restore's own safety snapshot, regardless of that backup's
own expiry.

## Who Can Restore

Store staff with the `backups.restore` permission (a DELIBERATELY separate,
stronger permission from `backups.view`/`backups.manage` — Owner-only by
default) can REQUEST a restore, which runs preflight and records an
auditable `BackupRestoreJob`. Only a Super Admin (`super_admin.platform`
group, reusing B16's existing boundary) can AUTHORIZE and EXECUTE one, since
execution replaces the entire shared database.

## What Happens After a Failed Restore

`RunRestoreJob` has `tries = 1` — a restore is never automatically retried
(re-running an import against a partially-restored database is itself a
data-integrity risk). A failure is recorded explicitly, with `failure_reason`,
and triggers a `restore.failed` outbox event/audit entry. Recovery requires a
human decision: the `pre_restore_backup_id` recorded on the `BackupRestoreJob`
is the documented recovery path (restore FROM that safety snapshot, going
through the same controlled workflow again) — B19 does not implement
automatic rollback, and this limitation is stated here rather than silently
assumed away.

## What Is NOT Backed Up (and Must Be Rebuilt, Never Restored)

Redis/application cache, queue/job tables, the outbox events and audit log
tables themselves, uploaded media/files (no storage infrastructure exists
yet to back up from), environment configuration and secrets. After a
restore, any domain's own cache (e.g., B17's `ConfigService` cache keys) must
be invalidated/rebuilt through that domain's OWN existing mechanism — B19
does not implement a blanket post-restore cache-flush job in this milestone
(named as a real, concrete follow-up, not fabricated as done).

## How Secrets Are Protected

`MysqldumpStrategy`/`MysqlRestoreStrategy` pass database credentials via a
temporary `--defaults-extra-file` (mode 0600, deleted immediately after use
in a `finally` block) — never a plaintext `-p<password>` argument, which
would leak into the process list visible to any other user on the same
machine. No encryption key, database credential, or artifact content is ever
placed in a log line, audit entry, or outbox event payload anywhere in this
domain.

## How Audit Information Is Handled

Every lifecycle transition (`backup.requested/failed/verified`,
`restore.requested/preflight_failed/authorized/completed/failed`) is
recorded via the existing, unmodified `Log::channel('audit')` mechanism and/
or `RecordsOutboxEvents` — the same pattern every phase since B14 has used.
No audit payload ever contains backup contents, a database dump, a raw
credential, or a signed storage URL.

## What Future Infrastructure Verification Is Required

See the checkpoint's dedicated Future VS Code/Claude Code Verification
Checklist — in summary: actual `mysqldump`/`mysql` execution against a real
MySQL 8.0+ instance, actual file upload/download through a real filesystem
or S3-compatible disk, actual scheduler/queue-worker execution, and a
genuine restore-into-a-controlled-environment rehearsal. None of these have
been performed in this Claude App sandbox.

## RPO / RTO

Not yet defined. Module 23 itself requires these to be "explicitly
documented rather than implied" but gives no concrete numeric target
anywhere in the source specification. Per this milestone's own instruction,
no value is invented here — this remains an open operational/business
decision for a future milestone or stakeholder to make explicitly.
