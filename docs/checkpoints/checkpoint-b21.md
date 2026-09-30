============================================================
PHASE B21 CHECKPOINT
============================================================

Phase:
Development Phase B

Module:
B21 — Runtime Verification of B0-B20 + Store Health, Monitoring &
Resource Usage (Module 24)

Implementation Summary:
For the first time the platform ran against a real runtime (PHP 8.4,
MySQL 8.0, Redis 7, Node 22). The first `php artisan migrate` failed on
its third migration and the first test run produced 567 errors out of
684 tests. B21 fixed every root cause (the runtime defects are listed in
docs/development/b21-inspection-findings.md), made each CI step pass, and
then built Module 24 on top of the verified baseline.

Runtime Verification (Part 1):
- Laravel skeleton files, composer.lock (PHP 8.3 platform), phpunit.xml,
  phpstan.neon, package-lock.json, ESLint 9 / Vite / PostCSS config added.
- 6 migration defects fixed; migrate, migrate:reset and db:seed all clean.
- Platform-wide defects fixed: public_id generation, factory resolution,
  middleware order vs. route-model binding, Sanctum tokenable tenant
  scope, Stringable TypeErrors, undefined audit log channel, ~20 outbox
  writes outside a transaction, platform-scope outbox events, colliding
  idempotency keys, unbound developer API routes.
- Module defects fixed: open redirect, refund concurrency and repeat
  partial refunds, dropped payment notes, category soft-delete orphans,
  zero-benefit promotions, analytics comparison period, theme rollback
  target, settings key disclosure, low-stock outbox collision, Super
  Admin nested routes and pagination.

New Components Implemented (Module 24):
App\Domain\Monitoring — StoreHealthService (ten checks), StoreHealthReport,
HealthCheckResult, HealthStatus, StoreHealthSnapshot, PlatformMonitoringService,
store-health:snapshot command (hourly), StoreHealthPolicy,
StoreHealthController, StoreHealthSnapshotResource;
SuperAdminMonitoringController; config/monitoring.php; Inertia page
StoreHealth/Index + HealthCheckList component. See
docs/architecture/b21-store-health.md.

Database Changes:
- New: store_health_snapshots, failed_jobs, personal_access_tokens.
- Corrected in place (no database could ever have run them): store_user/
  roles order, inventories index name, usage_counters DATETIME,
  backups.initiated_by length, outbox_events.store_id nullable.

Permissions:
store_health.view (PermissionSeeder; granted to the seeded Manager role).

Automated Test Status:
EXECUTED.
- Backend: 719 tests, 1218 assertions — all passing on MySQL 8.0
  (26 new Module 24 tests, 9 runtime-regression tests).
- Frontend: 5 Vitest tests passing; `npm run lint` and `npm run build` pass.
- Static analysis: PHPStan/Larastan level 5 — no errors.

Runtime Verification Status:

| Area | Status |
|------|--------|
| Composer install (PHP 8.3 platform lock) | EXECUTED |
| Migrations up / reset / seed (MySQL 8.0) | EXECUTED |
| PHPUnit suite (MySQL 8.0, Redis) | EXECUTED — PASSING |
| PHPStan level 5 | EXECUTED — CLEAN |
| Frontend lint / Vitest / Vite build | EXECUTED — PASSING |
| HTTP smoke test (php artisan serve): health, register, SPA cookie login, /api/v1/store/health, Inertia home page | EXECUTED |
| store-health:snapshot against a real database | EXECUTED |
| GitHub Actions CI run | NOT EXECUTED — runs on the next push to main/develop or a PR |
| Production deployment / VPS / Nginx / S3 / SSL | NOT EXECUTED — no target environment |
| Real mysqldump backup cycle | NOT EXECUTED — tests use fake dump strategies |

Known Limitations:
- Health thresholds are defaults, not measured values.
- No alert delivery for critical health (snapshot history is the hook).
- Host-level metrics remain out of scope (B20 boundary).
- A non-stateful staff login returns 200 without creating a session.

Recommended Next Milestone:
Phase B22 — Module 32 (Security, Audit & Compliance): the audit trail is
still a log channel only (storage/logs/audit-*.log); a queryable,
tamper-evident audit store is the most-referenced remaining gap. Running
the GitHub Actions workflow once on a pull request should precede it.
