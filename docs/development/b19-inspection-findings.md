# Phase B19 — Step 1: Inspection + Gap Analysis (Backup, Restore & Data Protection: Module 23)

## Critical Environment Constraint — Stated Upfront

This Claude App sandbox has **no PHP runtime, no MySQL server, no Redis, no
S3-compatible storage, and no queue worker process** — exactly as every
prior B0-B18 checkpoint has stated. Module 23 is unusually infrastructure-
dependent (a "backup" is inherently about real database dumps and real
object storage), so this milestone is explicitly, honestly scoped as
**architecture + implementation + code-level test preparation +
documentation**, per this milestone's own repeated instruction. Every claim
of "executed" below is qualified; anything requiring a real runtime is
marked `NOT EXECUTED — ENVIRONMENT LIMITATION`.

## Inspection of Existing Infrastructure (Gap Analysis Classification: A/B/C/D/E)

- **A. Already implemented and reusable**: Laravel's `Storage` facade
  (`Storage::disk('local')`, used unchanged by B12's
  `GenerateReportExportJob`) — the existing filesystem storage abstraction;
  Laravel's own queue/job system (B7-B18's own established job conventions —
  `ShouldQueue`, `tries`, `backoff()`); the existing scheduler
  (`routes/console.php`, `Schedule::command()`); B17's `ConfigService` (already
  uses `Crypt::encryptString()`/`decryptString()` for its own `secret` setting
  type — the existing encryption abstraction); the existing Outbox/audit
  mechanism (`RecordsOutboxEvents`, `Log::channel('audit')`); B16's Super
  Admin platform-global route group; B11's notification pipeline.
- **B. Partially implemented and needs extension**: none identified —
  no prior phase built any backup-adjacent code at all.
- **C. Missing and required**: the entire `Backup`/`BackupRestoreJob` domain,
  storage-adapter abstraction, database-dump strategy abstraction,
  lifecycle/state machine, retention command, restore preflight, Super Admin
  oversight, staff-facing APIs. All implemented this milestone.
- **D. Future infrastructure verification only**: actual `mysqldump`
  execution, actual file upload/download through `Storage::disk()`, actual
  Redis cache invalidation, actual scheduler firing, actual restore into a
  real database. All architected and code-complete; none executed here — see
  the Future VS Code/Claude Code Verification Checklist in the checkpoint.
- **E. Explicitly out of scope**: S3/object-storage adapter implementation
  (config-ready, not coded — no S3 SDK/credentials exist in this environment
  to build against meaningfully); point-in-time recovery (binlog-based —
  requires real MySQL binlog infrastructure this sandbox cannot provide or
  meaningfully architect beyond a documented dependency); cross-region/off-
  site backup (Module 23 §12's own "foundation" language, no concrete
  requirement); search/index rebuild integration (no search-index domain
  exists yet in B0-B18 to integrate with); B18 developer API exposure (Module
  31 never mentions backup/restore — per this milestone's own explicit
  instruction, not added).

## Backup Data Classification (Module 23 §4-5, Phase 3 of this milestone)

| Category | In Scope for B19's Backup? | Rationale |
|---|---|---|
| 1. Tenant-owned business data (orders, products, customers, inventory, etc.) | YES — via full database dump | The authoritative source for everything; Module 23 §23 "database backups" names this directly |
| 2. Tenant-owned uploaded/media data | NO (this milestone) | No S3/media storage infrastructure exists to back up from in this environment (Theme/branding logo URLs are external references, not platform-hosted files — see B15's own inspection findings); documented as deferred, not silently dropped |
| 3. Platform-global data (Package/Theme catalogs, platform settings) | YES — included in the same full database dump (all tables live in one shared MySQL database per this platform's architecture) | No separate platform-only backup format is invented; one dump captures both scopes, consistent with "shared MySQL, strict tenant isolation" |
| 4. Platform operational data (outbox events, jobs, request logs) | NO — explicitly excluded from the manifest's restorable scope | Operational/audit trails are not business data; restoring them would misrepresent history. Documented, not silently included |
| 5. Runtime secrets (`APP_KEY`, DB credentials, provider API keys in `.env`) | NEVER | Module 23 §14 "do not store encryption keys inside backup archives"; environment config is never part of an application-level backup |
| 6. Infrastructure configuration (`config/*.php`) | NO | Deployment-owned, not data; redeployment recreates it |
| 7. Temporary/generated data (cache, sessions) | NO | Rebuildable, not a data-loss risk |
| 8. Derived/cache data (Redis) | NO — invalidated/rebuilt after restore, never restored from a backup artifact | Module 23 Phase 23-24's own instruction: derived data is rebuilt, not restored |
| 9. Logs/audit data | NO | Explicitly named as excludable operational data; also protects the audit trail's own integrity (a restore should never appear to rewrite history) |

**What is NOT backed up in B19, stated plainly**: uploaded media/files (no
storage infrastructure to back up from yet), Redis/cache contents, queue/job
tables, the `outbox_events`/audit log tables themselves, environment
configuration and secrets. **What restore does NOT do**: it never restores
cache or search-index state — those are explicitly rebuilt/invalidated after
a restore completes (Phase 23-24), reusing whatever cache-invalidation
mechanism a table's own domain already has (e.g., B17's `ConfigService` cache
keys), never a new, second invalidation system.

## Architectural Decision — Database Backup Strategy Is a Real, Executable Design, Honestly Unexecuted Here

`MysqldumpStrategy` shells out to the real `mysqldump` binary via Laravel's
`Process` facade — genuine, correct, production-shaped code (not a fake
"copy some files" substitute Module 23 explicitly warns against). This
sandbox has neither a MySQL server nor (most likely) the `mysqldump` binary
itself, so this is honestly `NOT EXECUTED — ENVIRONMENT LIMITATION`
everywhere in this milestone's documentation, never claimed as verified.

## Architectural Decision — One Full-Platform Dump, Not Per-Tenant SQL Extraction

Module 23 §20-21 asks for "tenant backups" and "tenant backup isolation."
Given this platform's own established architecture — shared MySQL, every
tenant-owned table filtered by `store_id` via `BelongsToTenant` — a
genuinely isolated PER-TENANT SQL extract (e.g., `mysqldump --where="store_id=X"`
per table) is technically possible but would require enumerating every
tenant-owned table by hand and keeping that list in sync with every future
migration, a maintenance burden with no established precedent anywhere in
B0-B18. **Decision**: B19's `Backup` model supports a `store_id` column (NULL
for a platform-wide backup, set for a "tenant view" backup), but the ACTUAL
backup artifact for both is currently the same full-database dump — a
store-scoped `Backup` row's manifest records which store it is FOR (for
retention/authorization/audit purposes), while the underlying SQL artifact
is the platform's own consistent snapshot. **Tenant isolation is enforced at
the metadata/access layer** (a store cannot see, download, or request
restore of another store's `Backup` row) **and restore-side** (Phase 20
below) rather than by shipping a different SQL file per tenant. This is
documented explicitly, not silently narrowed, and is the smallest
architecture-compatible choice given the "one shared core platform" model
this project has used since Phase B0.

## Architectural Decision — Restore Is Platform-Level Only in B19

Given the full-dump architecture above, an actual RESTORE operation
necessarily replaces the ENTIRE shared database — there is no safe, tested
mechanism in this milestone to restore "only Store A's rows" from a full
dump without an extraction/merge tool this milestone does not build (Module
23 §16 itself calls tenant-level recovery "where architecturally possible" —
an explicit hedge). **Decision**: `BackupRestoreJob.target_store_id` is
recorded for every restore (audit/authorization purposes, and to make a
future per-tenant extraction tool a natural extension point), but the
RESTORE EXECUTION ITSELF is a Super-Admin-only, platform-wide operation in
B19 — a Store Admin can REQUEST a restore (creating an auditable record and
notifying Super Admin), but only Super Admin can actually authorize and
execute one, since executing it affects every tenant sharing the database.
This is the safest, most honest resolution of Module 23's own hedge, not an
invented restriction.

## Architectural Decision — Backup States (Module 23 §17's Own "Suggested," Not Mandatory, List)

B19 implements a practical subset: `created, queued, running, verifying,
verified, failed, expired, deleted, restoring, restored, restore_failed,
cancelled` — 12 of Module 23's own 14 suggested states. `uploading` is folded
into `running` (this milestone's storage adapter performs dump-and-store as
one job step, not a separately-observable upload phase) and
`partially_verified` is omitted (no concrete definition of "partial"
verification exists without real infrastructure to make it meaningful) —
both omissions are the "suggested, not mandatory" list's own explicit
allowance, not silent scope-narrowing.

## Architectural Decision — RPO/RTO Are Documented as Undefined, Not Invented

Module 23 §10-11 itself says RPO/RTO "must be explicitly documented rather
than implied" but gives no concrete numeric targets anywhere in the source
document. Per this milestone's own explicit instruction ("do not invent
RPO/RTO values unless the project specification defines them... mark them as
requiring future operational/business decision"), B19's data-protection
documentation states plainly that no RPO/RTO target is yet defined for this
platform, rather than fabricating a plausible-sounding number.

## Scope Decision Summary

**B19 implements**: `Backup` + `BackupRestoreJob` models/migrations, a
provider-neutral `BackupStorageAdapter` interface (`LocalBackupStorageAdapter`
implemented against Laravel's `Storage` facade; an S3 adapter's config
surface is named but not coded — see Gap Analysis "E"), `MysqldumpStrategy`
(real, unexecuted-here `mysqldump` invocation), `BackupService` (create/
verify/expire lifecycle), `RestoreService` (preflight → pre-restore safety
backup → platform-wide restore, Super-Admin-executed), `BackupPolicy` (a
stronger, separate `restore` permission per Module 23 §17), retention via a
new scheduled artisan command (reusing the existing scheduler, no second
one), 2 new B17 settings (`backup.retention_days`,
`backup.automated_backups_enabled`), outbox events + `Log::channel('audit')`
entries for every lifecycle transition, B11-integrated notifications
(backup completed/failed, restore started/completed/failed), staff-facing
APIs (request/view/download-preflight for a store's own backups; request-
restore only), Super-Admin APIs (platform-wide backup oversight, initiate
platform backup, authorize + execute restore).

**Explicitly deferred** (named so nothing is silently dropped): media/file
backup (no storage infrastructure exists to back up from), S3 adapter
implementation, point-in-time recovery, cross-region/off-site backup,
per-tenant SQL extraction/restore, search-index backup (no search domain
exists), B18 developer API exposure (Module 31 never mentions it),
disaster-recovery rehearsal (documentation-only, per this milestone's own
explicit "do not claim disaster-recovery tested"), specific numeric RPO/RTO
targets (undefined pending a business decision).

## Second Bug Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

`ExpireOldBackupsCommand` originally called `BackupStateMachine::transition()`
for the first status change (Verified → Expired) but then used a raw
`$backup->update(['status' => Deleted])` for the second transition,
bypassing the state machine's own validation entirely for that step. Caught
before being left in the codebase — fixed by routing BOTH transitions
through `BackupStateMachine::transition()` (re-fetching the model via
`->fresh()` between them so the second call sees the just-committed
`Expired` status, since `expired → deleted` and `failed → deleted` are both
valid transitions in the state machine's own map).
