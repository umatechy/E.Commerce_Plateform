# Phase B19 — Backup, Restore & Data Protection Architecture (Module 23)

See `docs/development/b19-inspection-findings.md` for the full gap analysis,
backup data classification, and two design-time bugs found and fixed.

## The Central Environment Constraint, Stated Plainly

This Claude App sandbox has no PHP runtime, no MySQL server, no Redis, no
S3-compatible storage, and no queue worker. Every claim in this document
about the backup/restore ARCHITECTURE being implemented is real and code-
complete; every claim about it being EXECUTED is explicitly marked `NOT
EXECUTED — ENVIRONMENT LIMITATION`. See the checkpoint's status matrix and
Future VS Code/Claude Code Verification Checklist for the complete,
itemized list of what genuinely still needs to run against real
infrastructure.

## One Full-Platform Dump, Tenant Isolation at the Metadata/Access Layer

Given this platform's shared-MySQL, `BelongsToTenant`-filtered architecture,
a genuinely per-tenant SQL extraction would require enumerating every
tenant-owned table by hand — a maintenance burden with no precedent
anywhere in B0-B18. `Backup.store_id` records which store a backup is FOR
(authorization/audit/retention purposes); the underlying SQL artifact is
always the platform's own full, consistent snapshot. Tenant isolation is
therefore enforced entirely at the metadata/access layer: `Backup`
deliberately does NOT use `BelongsToTenant` (the same reasoning B17's
`SettingRevision` already established — a backup legitimately spans both
Platform and Store scope), so every controller applies an EXPLICIT
`store_id` check — proven by `BackupTenantIsolationTest`, the single most
security-critical test file this milestone produces.

## Provider-Neutral Storage — Real Code, Genuinely Swappable

`BackupStorageAdapter` (interface) → `LocalBackupStorageAdapter` (real,
against Laravel's own `Storage::disk('local')`, the same disk B12's
`GenerateReportExportJob` already uses). `BackupService`/`RestoreService`
depend only on the interface — a future S3-compatible adapter is a one-line
container-binding change in `AppServiceProvider`, never a change to business
logic.

## Real Database Dump/Restore Strategy — Not a Fake Filesystem Copy

`DatabaseDumpStrategy` → `MysqldumpStrategy` shells out to the real
`mysqldump` binary (`--single-transaction` for InnoDB consistency without
locking the whole database, `--routines --triggers --events` for full
schema fidelity) via Laravel's `Process` facade — genuine, production-shaped
code, honoring Module 23's own explicit "do not implement a fake database
dump." Credentials are passed via a temporary `--defaults-extra-file`, never
as a plaintext `-p<password>` command-line argument (which leaks into the
process list). `DatabaseRestoreStrategy` → `MysqlRestoreStrategy` mirrors
this for the `mysql` client. **Neither has ever been executed in this
sandbox** — no MySQL server or binary exists here.

## Backup Lifecycle — 12 of Module 23's Own 14 Suggested States

`created → queued → running → verifying → verified → (expired|deleted)`, with
`restoring → (restored|restore_failed) → verified` for the restore-and-
recover-back-to-verified path, and `failed → deleted`. `uploading` and
`partially_verified` are omitted (Module 23's own list is explicitly
"suggested," not mandatory) — see inspection findings for the specific
reasoning. `BackupStateMachine` validates every transition explicitly;
an invalid one throws rather than silently succeeding.

## Integrity — Verified Is a Separate, Later Step From Created

`BackupService::verify()` re-reads the STORED artifact (never trusts the
in-memory dump) and re-checks its size against what was recorded — Module
23's own "a successful upload does not equal a verified backup." A mismatch
throws `BackupIntegrityException`, which the state machine turns into a
`Failed` transition, never a silently-accepted corrupt artifact.

## Bugs Found During Implementation

1. `webhook_delivery_attempts`... (that was B18). B19's own: `ExpireOldBackupsCommand`
   originally used a raw `$backup->update()` for its second state transition
   (Expired → Deleted), bypassing `BackupStateMachine`'s own validation for
   that step. Fixed by routing both transitions through the state machine.

## Retention — Reuses the Existing Scheduler, Protects Active/Referenced Backups

`backups:expire` (new artisan command) is registered via the SAME
`routes/console.php` every other scheduled command already uses — no second
scheduler. It structurally excludes `restoring`/`restored` status backups
(the WHERE clause only ever matches `verified`/`failed`) and any backup
referenced as ANY restore job's `pre_restore_backup_id`, regardless of that
job's own status — proven by dedicated tests.

## Deferred (see inspection findings for the full, explicit list)

Media/file backup (no storage infrastructure exists to back up from), S3
adapter implementation, point-in-time recovery, cross-region/off-site
backup, per-tenant SQL extraction/restore, search-index backup, B18
developer API exposure (Module 31 never mentions it), specific numeric
RPO/RTO targets (undefined pending a business decision).
