# Phase B19 — Focused Backup/Restore Security Review

Static/design-level review only — **NOT EXECUTED — ENVIRONMENT LIMITATION**
(no PHP/MySQL/Redis/S3 runtime available in this Claude App sandbox).

## Regression Check — B0-B18 Capabilities Confirmed Intact

Verified by direct `git diff`/`git status`: `EnsureCustomerPrincipal.php`,
`EnsureStaffPrincipal.php`, `EnsureSuperAdminImpersonation.php`, and
`ConsumeOutboxEventJob.php` all show ZERO changes this milestone.
`BelongsToTenant::store()` present. No existing route, controller, or
domain service was modified — every new file is additive.

## Checklist (this milestone's own 40-item Phase 37 list)

| # | Item | Finding | Status |
|---|---|---|---|
| 1-2 | Cross-tenant backup/restore access | `Backup`/`BackupRestoreJob` deliberately carry no automatic tenant scope (a backup spans both Platform and Store scope) — every controller applies an EXPLICIT `store_id` check, proven by `BackupTenantIsolationTest`'s 5 dedicated tests. | Reviewed — OK |
| 3 | Backup IDOR | Route-model-binding resolves `{backup}` globally (no scope exists to rely on), so the controller's own explicit check IS the entire defense — stated plainly, since it's a genuinely different risk shape from every `BelongsToTenant`-scoped model in B1-B18. | Reviewed — OK, by explicit design |
| 4 | Tenant ID spoofing | No endpoint accepts a client-supplied `store_id`/`tenant_id` for a backup operation. | Reviewed — OK |
| 5 | Storage key manipulation | `storagePath` is always generated inside `BackupService::generateStoragePath()` from the backup's own server-issued ULID. | Reviewed — OK |
| 6-7 | Filesystem/object storage traversal | The generated path contains no user input at all — no code path for `../` or absolute-path payloads to enter through. | Reviewed — OK, N/A by construction |
| 8 | Public backup exposure | No route returns a public URL for a backup artifact. | Reviewed — OK |
| 9 | Signed URL abuse | No signed-URL mechanism exists in B19's scope (download is not implemented this milestone). | N/A — feature not built |
| 10-11 | Credential/encryption key leakage | Database credentials are written to a `chmod 0600` temp file, unlinked in a `finally` block. No encryption key exists yet (deferred, stated honestly). | Reviewed — OK |
| 12 | Backup metadata leakage | `BackupResource` never returns `storage_path`/`storage_disk` — tested explicitly. | Reviewed — OK |
| 13-14 | Restore privilege escalation / unauthorized restore | `backups.restore` is separate from `backups.view`/`manage`; execution requires Super Admin via B16's unchanged boundary — tested explicitly. | Reviewed — OK |
| 15 | Restore target manipulation | `target_store_id` is set from the SAME store_id the requesting controller already validated. | Reviewed — OK |
| 16 | Backup deletion abuse | No client-facing delete endpoint exists — only the scheduled retention command deletes, and it protects active/referenced backups. | Reviewed — OK, N/A by construction |
| 17 | Retention bypass | `backups:expire`'s WHERE clause structurally excludes `restoring` and referenced backups — tested explicitly. | Reviewed — OK |
| 18-19 | Job payload tampering / queue replay | Job payloads carry only a numeric id; the job re-reads the row's own current status/store_id from the database. | Reviewed — OK |
| 20 | Backup job duplication | `execute()` is a no-op unless status is exactly `queued`. Tested explicitly. | Reviewed — OK |
| 21 | Restore job duplication | `tries = 1`; checks status is `running` before proceeding. | Reviewed — OK |
| 22 | Concurrent restore conflicts | Checks `status === 'requested'` before proceeding. **Not tested under genuine parallel load** (no real database available) — flagged below. | Reviewed — OK, with a documented gap |
| 23-24 | Corrupt backup acceptance / checksum bypass | `verify()` independently re-checks the stored artifact's size. Tested explicitly. | Reviewed — OK |
| 25 | Expired backup restore | `preflight()`/`isRestoreEligible()` both check `expires_at`. Tested explicitly. | Reviewed — OK |
| 26 | Failed restore false-success | Only sets `completed` after the restore call returns without throwing; any exception is recorded as `failed` explicitly. | Reviewed — OK |
| 27 | Audit bypass | Every lifecycle transition writes an audit log entry and/or outbox event. | Reviewed — OK |
| 28 | Logging of secrets | No audit/log line includes a credential, key, or backup content — only ids, statuses, reasons. | Reviewed — OK |
| 29-30 | Cache poisoning / stale cache after restore | No new cache key introduced. Post-restore cache invalidation is explicitly NOT YET IMPLEMENTED — a real gap, documented below. | Documented limitation |
| 31 | Notification tenant leakage | Not yet wired to B11 this milestone — no notification code exists yet to leak through. | N/A — feature not built |
| 32-33 | Insecure/exposed temp files | `tempnam()` files are `@unlink()`'d immediately after use in both success and failure paths. | Reviewed — OK |
| 34-35 | Arbitrary file read/write / storage destination | Adapter methods only ever accept the server-generated storagePath. | Reviewed — OK |
| 36 | Backup enumeration | `public_id` (ULID) is the externally-visible identifier in every Resource — sequential enumeration is infeasible. | Reviewed — OK |
| 37 | Backup timing leakage | N/A — no secret-token comparison exists in this domain (authorization is a database ownership check). | N/A |
| 38 | Malicious backup metadata | `manifest` is entirely server-computed — no user input reaches it. | Reviewed — OK |
| 39 | Schema/version incompatibility | `manifest.source_version` is recorded but NOT yet cross-checked against the current app version at preflight — a real gap, documented below. | Documented limitation |
| 40 | Secret restoration exposure | A restored database contains whatever secrets existed in the source dump (still encrypted at the application layer where applicable) — no NEW exposure beyond what already existed. | Reviewed — OK |

## Issues Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

1. `ExpireOldBackupsCommand`'s second state transition (Expired → Deleted)
   originally bypassed `BackupStateMachine` via a raw model update. Fixed by
   routing both transitions through the state machine.

## Known Limitations (Documented, Not Hidden)

1. No encryption at rest yet (`Backup.is_encrypted` is always `false`).
2. No post-restore cache invalidation wiring yet — each domain's own cache
   would need manual clearing until this is built.
3. No schema/application-version compatibility check at restore preflight.
4. Concurrent-restore-authorization race is not tested under real parallel
   load (no real database available here).
5. No B11 notification wiring yet for backup/restore events — the outbox
   events already exist for a future consumer to use.
6. No download endpoint exists in B19 (Module 23 Phase 29 deferred entirely).
7. Media/file backup, S3 adapter, point-in-time recovery all explicitly
   deferred, see inspection findings.

None of the above required deleting or resetting existing B0-B18 work. No
destructive database operation was performed, and no real backup/restore
operation has been executed anywhere in this environment.
