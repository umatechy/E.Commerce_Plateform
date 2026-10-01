# Backup and Restore — Operations

Status: 2026-10-01, Phase B30 (gap G4). Sources: Module 23, SRS BKP-001–007,
HEALTH-005, TEST-011, owner decisions of 2026-09-30 §4–5.

This describes what the platform does today. Where something is not built or
was not run, it says so.

## What is backed up

| Data | Backed up? | How |
|---|---|---|
| The database (every store's data, platform data, settings, audit log) | Yes | `mysqldump --single-transaction` of the whole database |
| One store's data on its own | No separate artifact | The database is shared. A "store" backup (requested by a store) is a full dump recorded against that store. It is never handed to the store. |
| Product images and other files on the media disk | **Not implemented** | Use the storage provider's own versioning or replication. |
| `.env`, the application key, the backup encryption key, provider credentials | **Never** in a backup, by design | Keep them in the hosting provider's secret store. Losing them is not recoverable from a backup. |
| Point-in-time recovery, incremental backups, snapshots | **Not implemented** | — |

## Setting it up

1. **Binaries.** `mysqldump` and `mysql` must be on the server's PATH, or set
   `BACKUP_MYSQLDUMP_BINARY` and `BACKUP_MYSQL_BINARY` to their full paths.
2. **Database account.** The application's account needs, on the application
   database: `SELECT`, `SHOW VIEW`, `TRIGGER`, `EVENT` (and `SHOW_ROUTINE` on
   MySQL 8.0.20+ if stored routines exist). This list has not been verified
   with a restricted account: the runs so far used an administrative account,
   locally and in CI. For the restore
   rehearsal it also needs `CREATE`, `DROP` and all data privileges on
   databases named `<database>_rehearsal_%`, for example:

   ```sql
   GRANT ALL PRIVILEGES ON `umartechy\_ecommerce\_rehearsal\_%`.* TO 'app'@'%';
   ```

   Without that grant the rehearsal fails with the server's "access denied"
   message and raises an alert. It never reports success.
3. **Encryption key.** Run `php artisan backups:generate-key` and set the
   output as `BACKUP_ENCRYPTION_KEY`. Keep a copy somewhere that is **not**
   the backup storage. Without the key, encrypted backups cannot be restored.
   Without a key configured, backups are stored unencrypted and the Super
   Admin page says so.
4. **Storage.** `BACKUP_DISK` names a Laravel filesystem disk (default: the
   private `local` disk). It must be private. An S3-compatible disk works
   through the same abstraction but has not been run.
5. **Scheduler and queue.** The backup commands are on the Laravel scheduler
   (`php artisan schedule:run` every minute, see `deploy/crontab.example`).
   The dump runs in a queued job, so a queue worker must be running. The
   outbox publisher (`outbox:publish`) delivers the alerts.
6. **Alert recipients.** Set the platform setting
   `alerts.critical_email_recipients` (a list of addresses). While it is
   empty, alerts go to every active platform staff account.

## Schedule (UTC)

| When | Command | What it does |
|---|---|---|
| Daily 02:00 (`BACKUP_SCHEDULE_AT`) | `backups:run-scheduled` | Requests the day's platform backup. On the 1st it is the monthly backup. One per day, however often it runs. |
| Daily 04:00 (`BACKUP_VERIFY_AT`) | `backups:verify --deep` | Reads the newest backup back from storage: size, checksum, full decrypt and decompress. |
| Hourly | `backups:monitor` | Alerts when there is no verified backup within the RPO target, or the last two scheduled backups failed. One alert per day per condition. |
| Sunday 05:00 (`BACKUP_REHEARSAL_DAY`, `BACKUP_REHEARSAL_AT`) | `backups:rehearse` | Restore rehearsal of the newest verified platform backup. |
| Daily 00:00 | `backups:expire` | Retention. |

Switches (platform settings): `backup.automated_backups_enabled`,
`backup.rehearsal_enabled`.

## Retention

| Tier | Kept | Setting |
|---|---|---|
| Daily | 30 days | `backup.retention_days` |
| Monthly (the backup of the 1st) | 12 months | `backup.monthly_retention_months` |
| Manual, and the safety backup taken before a restore | 30 days | `backup.retention_days` |

Never deleted, whatever the date: the newest verified platform backup; a
backup a restore or rehearsal is using or waiting for; a safety backup a
restore job refers to. Nothing is deleted while a restore or rehearsal runs.

The 90-day rule for closed or suspended stores (owner decision §5) is **not
enforced**: stores cannot be closed yet (gap G14), and there is no per-store
artifact to keep.

## A backup, step by step

1. A row is created (`created` → `queued`) with its tier, expiry and request
   ID. Audit: `backup.created`.
2. `RunBackupJob` claims it (`running`). Only one worker can.
3. `mysqldump` writes the dump. It must end with mysqldump's completion line.
4. The dump is gzip-compressed and, with a key, encrypted (XChaCha20-Poly1305
   in chunks). Its SHA-256 is taken.
5. It is written to backup storage under an opaque name. Audit:
   `backup.completed`.
6. Verification reads the stored artifact back: it exists, has the recorded
   size and checksum, and decodes completely. Then the backup is `verified`.
   Audit: `backup.verified`.
7. On any failure the backup is `failed` with the reason and the stage
   (`dump`, `encode`, `store`, `verify`), an alert is raised, and the queue
   retries the job; a retry of a backup that already ran does nothing.

Local working copies of the dump are removed in every case.

## Restore rehearsal

`php artisan backups:rehearse` (or "Rehearse" on the Super Admin Backups
page) does this without touching live data:

1. Checks the artifact in storage (size, checksum).
2. Decrypts and decompresses it.
3. Creates a database `<database>_rehearsal_<random>` and imports the dump.
4. Checks: the core tables and the migration history are there; the schema
   against the live schema; no row points at a missing parent (every foreign
   key); no row is linked to a row of another store.
5. Drops the rehearsal database, also when a check failed.
6. Records the result, the checks and the durations. A failure raises an
   alert.

Exit code 0 means it passed. Anything else means it failed or could not run.

## Production restore

A production restore replaces the whole live database: **every store's data**.
It is a Super Admin action behind MFA and step-up.

1. A store (owner, or a role with `backups.restore`) requests it for one of
   its backups, or platform staff decide on it for a platform backup. The
   request is preflighted: backup verified, not expired, artifact intact.
2. **Before authorizing**, on the server: `php artisan down`, stop the queue
   workers and the scheduler. The application does **not** do this for you.
3. Platform staff authorize it on the Backups page, or with
   `POST /api/v1/super-admin/restore-jobs/{id}/authorize`
   (`confirmation` = the backup id, `reference` = the incident or change).
   The server refuses without step-up, without the typed backup id, without
   a reference, if another restore is running, or if the artifact fails its
   integrity check. A refusal changes nothing.
4. A safety backup of the current database is taken and verified first. If
   it fails, the restore does not start.
5. The restore job decodes the artifact and imports it with the `mysql`
   client.
6. Afterwards: `php artisan migrate` (the backup may be older than the code),
   `php artisan audit:verify`, check the health endpoints, start the workers,
   `php artisan up`.

**There is no automatic rollback.** If the import fails, the database may be
partly restored. The way forward is to restore the safety backup of step 4.
A failed restore is recorded as failed and raises "PRODUCTION RESTORE FAILED".

**Not executed.** A production restore has not been run against a live
database. The import path is the one the rehearsal runs.

## Recovery objectives

Targets from the owner's decision: **RPO 24 hours, RTO 4 hours**. They are
targets, not measured guarantees.

- RPO: the scheduled backup is daily, so the designed worst case is about 24
  hours plus the backup's run time. `backups:monitor` alerts once it is
  exceeded.
- RTO: the only measurement is the rehearsal on a development machine: a
  4.9 MB database (90 tables) was verified, decoded, imported and checked in
  9.0 seconds, of which the import was 6.8 seconds. That says nothing about
  a production-sized database on production hardware. Each rehearsal records
  its duration; read the trend there.

## Commands

| Command | Use | Exit code |
|---|---|---|
| `backups:run-scheduled` | Today's backup | 0 |
| `backups:verify [--all] [--deep]` | Re-check stored artifacts | 1 if any failed |
| `backups:monitor` | Overdue / repeated failure | 1 if a problem was found |
| `backups:rehearse [--backup=ID] [--force]` | Restore rehearsal | 1 if it failed or could not run |
| `backups:expire` | Retention | 1 if a backup could not be deleted |
| `backups:generate-key` | Print a new encryption key | 0 |

## What is not there

- Media/object backup, point-in-time recovery, incremental backups.
- Automatic maintenance mode and automatic rollback around a restore.
- A restore of one store only.
- Off-site copies and immutability: one disk holds the backups. Use a remote
  disk, and the provider's object lock, for real disaster recovery.
- A WhatsApp provider. With `alerts.whatsapp_enabled` on, the alert is
  recorded and marked failed ("channel not configured") until gap G10.
- Downloading a backup: there is no endpoint, for anyone.
