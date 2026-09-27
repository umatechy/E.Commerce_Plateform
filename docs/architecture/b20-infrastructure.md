# Phase B20 — Hosting & Infrastructure Architecture (Module 20)

See `docs/development/b20-inspection-findings.md` for the full gap analysis
and the Infrastructure Responsibility Matrix. This document covers every
infrastructure layer Module 20 names; each section states DESIGNED /
IMPLEMENTED / INSPECTED / `NOT EXECUTED — ENVIRONMENT LIMITATION` explicitly.

## Application Runtime (Laravel 12 / PHP 8.3+)

**IMPLEMENTED** (already existed, confirmed by inspection): `.env.example`
documents `APP_ENV`, `APP_DEBUG`, `APP_TIMEZONE=UTC`. **IMPLEMENTED this
milestone**: `deploy/php-fpm/umartechy-pool.conf.example` sets
`opcache.enable=1`, `opcache.validate_timestamps=0` (production — requires
an explicit OPcache reset on every deploy, documented in the deployment
guide below), `upload_max_filesize`/`post_max_size=20M`, `memory_limit=256M`,
and disables `expose_php` (Non-Negotiable: never expose PHP runtime info
publicly). Worker/pool sizing is marked `REQUIRES CAPACITY VALIDATION` —
Module 20 gives no numeric target.

## Nginx / PHP-FPM

**IMPLEMENTED** (template): `deploy/nginx/umartechy.conf.example` — correct
Laravel `try_files` routing, PHP-FPM unix-socket upstream, HTTP→HTTPS
redirect, `.env`/`.git`/private-storage/backup-artifact denial rules,
security headers (`X-Content-Type-Options`, `X-Frame-Options`,
`Referrer-Policy`), static-asset caching. **NOT EXECUTED — ENVIRONMENT
LIMITATION**: never installed or `nginx -t`-validated in this sandbox.

## MySQL

**IMPLEMENTED** (already existed): `.env.example`'s `DB_*` variables;
ADR-001/003's shared-database, tenant-scoped-by-column architecture. B20
adds: the deployment guide's explicit instruction to create a
LEAST-PRIVILEGE application database user (`GRANT SELECT, INSERT, UPDATE,
DELETE` — never `GRANT ALL` or the `root` account) — Non-Negotiable, "do not
use root credentials for the application unless explicitly justified." **NOT
EXECUTED — ENVIRONMENT LIMITATION**: no real MySQL server exists here to
create that user against.

## Redis

**IMPLEMENTED** (already existed): `.env.example`'s `REDIS_*` variables;
every cache key across B10-B19 is already tenant-scoped by construction
(e.g., `settings:store:{storeId}:{key}` from B17, `rate limiter by ApiKey id`
from B18) — confirmed by inspection, not re-invented. Redis is never the
authoritative source of business data anywhere in B0-B19 (confirmed by
inspection: every domain's Redis usage is cache/queue/rate-limit-counter
only). **NOT EXECUTED — ENVIRONMENT LIMITATION**: no real Redis server
exists here.

## Queues & Workers

**IMPLEMENTED** (already existed + this milestone's own inspection):
every queued job since B7 uses Laravel's own `ShouldQueue` — B20 adds no
second queue system. `deploy/supervisor/umartechy-worker.conf.example`
templates `php artisan queue:work redis --tries=3` under Supervisor process
management (`autorestart=true` — a crashed worker restarts automatically).
Every job across B7-B19 already establishes its own tenant context
server-side from the job's OWN database row (confirmed by inspection —
B18's `DispatchWebhookJob`, B19's `RunBackupJob`/`RunRestoreJob` are the most
recent, explicitly-documented examples) — B20 does not change this pattern,
only documents that the worker process itself must run as the same
non-root deployment user. **NOT EXECUTED — ENVIRONMENT LIMITATION**: no real
worker process has run in this sandbox.

## Scheduler

**IMPLEMENTED** (already existed): `routes/console.php` already lists every
scheduled command (`outbox:publish`, `inventory:expire-reservations`,
`marketing:detect-abandoned-carts`, `backups:expire`). `deploy/crontab.example`
is the ONE required production cron entry (`* * * * * php artisan
schedule:run`) — Laravel's scheduler remains the single source of truth for
WHEN each task runs; cron's only job is invoking it every minute. **NOT
EXECUTED — ENVIRONMENT LIMITATION**: no real cron daemon has run this here.

## Filesystem

**IMPLEMENTED** (already existed + confirmed by inspection): `local` disk
(private, non-public by Laravel's own default) already used by B12's report
exports and B19's backup artifacts — both under paths that are server-
generated and never client-supplied (confirmed for B19 by
`BackupTenantIsolationTest`). Nginx template denies direct access to
`/storage/app/` (except `public/`) and `/backups/` paths explicitly.

## S3 / Object Storage

**DESIGNED, not newly coded this milestone**: `.env.example`'s `AWS_*`
variables (already provider-neutral via `AWS_ENDPOINT`, confirmed by
inspection) are the config surface; B19's `BackupStorageAdapter` interface
is already the correct abstraction point for a future S3 implementation
(named as deferred in B19's own checkpoint, not re-invented here). **NOT
EXECUTED — ENVIRONMENT LIMITATION**: no S3-compatible credentials/service
exist in this sandbox to build or test a concrete adapter against
meaningfully.

## Domain & DNS

**IMPLEMENTED** (already existed, confirmed by inspection, unchanged):
B14's `DomainResolverService`/`ResolveTenantContext` remain the sole
authority. Flow, confirmed unbroken: `HTTP Host → Nginx (routes every host
to the same Laravel app) → ResolveTenantContext → DomainResolverService →
Store/Tenant → TenantContext`. The Nginx template's `server_name
PLATFORM_DOMAIN *.PLATFORM_DOMAIN` intentionally routes every possible
tenant subdomain/custom-domain to the SAME application — Nginx never makes a
tenant-identity decision itself, only the application does (Non-Negotiable).
**NOT EXECUTED — ENVIRONMENT LIMITATION**: no real DNS records exist for
this platform.

## Cloudflare / CDN / WAF

**DOCUMENTED boundary only** (Module 20 explicitly asks for this, not an
implementation): Cloudflare, if used, owns DNS/TLS-at-edge/DDoS-WAF/edge
caching — it must NEVER be the source of truth for tenant identity
(Non-Negotiable), and any edge caching rule must exclude authenticated
API responses (`/api/v1/...` with an `Authorization` header) and any
tenant-specific public response from being cached across tenants (the
existing `Vary`-equivalent is the Host header itself, since every tenant's
storefront is domain-distinguished per B14). No Cloudflare account/zone
exists to configure in this sandbox.

## SSL/TLS

**DOCUMENTED + templated**: the Nginx template listens on 443 with
Let's Encrypt-conventional certificate paths; HTTP→HTTPS redirect is
unconditional. Certificate issuance/renewal (`certbot`) is a real, external
operation this sandbox cannot perform. **NOT EXECUTED — ENVIRONMENT
LIMITATION**.

## Secrets & Environment Configuration

See `docs/architecture/b20-secrets-and-environments.md` for the full
variable-by-variable classification.

## Deployment Process

**DESIGNED**: `CODE → composer install --no-dev → npm ci && npm run build →
php artisan migrate --force → php artisan config:cache/route:cache/view:cache
→ php artisan queue:restart (workers pick up new code on next job, never
mid-job) → reload PHP-FPM (picks up new OPcache) → infrastructure:health-check
(this milestone's own command — a non-zero exit stops the deployment script)
→ release`. Maintenance mode (`php artisan down`/`up`, Laravel's own existing
mechanism) is used only around the migration step if a migration is
genuinely breaking — most of B0-B19's own migrations are additive-only
(confirmed by every phase's own checkpoint), so this is rarely needed.
**NOT EXECUTED — ENVIRONMENT LIMITATION**: no real deployment has run.

## CI/CD

**IMPLEMENTED** (already existed, confirmed mature by inspection):
`.github/workflows/ci.yml` already runs PHPStan, migrations, PHPUnit, and a
frontend lint/test/build job. B20 does not duplicate or replace this — a
genuine PRODUCTION DEPLOY job (SSH/rsync to a real host) is deliberately NOT
added in this milestone, since no real deployment target/credentials exist
to configure it against safely; adding one now would either be non-
functional placeholder code or would need real secrets this sandbox cannot
hold. Documented as a concrete follow-up for the real VS Code/Claude Code
environment.

## Staging Environment

**DESIGNED**: `docker-compose.yml` provides local/staging parity (app +
queue-worker + scheduler + MySQL + Redis, each a separate container) — NOT
a production topology (a single-host Compose file does not match this
platform's actual production VPS/managed-hosting target). A genuine staging
deployment must use its OWN separate database, Redis, storage bucket,
domain, and secrets from production — never share any of them (Non-
Negotiable, Module 20 Phase 38).

## Production Environment

**DOCUMENTED requirements**: `APP_ENV=production`, `APP_DEBUG=false` (Non-
Negotiable — verbose error pages must never appear in production), a
securely-generated `APP_KEY` (never reused from a lower environment), real
MySQL/Redis/storage/HTTPS/domain, queue workers under Supervisor, cron
scheduler, monitoring, B19 backups configured and running, centralized
logging, secrets from a real secrets store (not committed `.env`).

## Monitoring & Health

**IMPLEMENTED this milestone**: `InfrastructureHealthService` (DB/cache/
storage connectivity checks), `GET /api/v1/public/health` (minimal, safe,
for a load balancer), Super-Admin-only detailed view (B16's existing
platform-global group), and `infrastructure:health-check` artisan command
(deployment smoke test). This is APPLICATION health — distinct from
INFRASTRUCTURE health (server CPU/disk/network, requires host-level
instrumentation this sandbox cannot provide) and TENANT STORE health (a
future Module 24 concern, not built here, not duplicated).

## Backup/Restore Integration

**DOCUMENTED boundary** (B19 remains fully authoritative for the business
logic): B20's job is only the infrastructure B19's own strategies/adapters
need — a real MySQL host reachable by `mysqldump`/`mysql`, a real
filesystem/S3 disk, a running scheduler (`backups:expire`, already
registered) and queue worker (`RunBackupJob`/`RunRestoreJob`). No B19 logic
is duplicated or modified here (confirmed by `git diff`).

## Deployment Rollback

**DOCUMENTED**: application code rollback (redeploy the previous Git
commit/build artifact) is always safe. Database migration rollback is
**conditionally safe** — every migration since B0 has been additive-only
(new tables/columns, confirmed by every phase's own checkpoint), so a code
rollback while an additive migration remains applied is normally harmless
(old code simply ignores the new column/table). A rollback is UNSAFE
whenever a migration has ever dropped/renamed a column (none has, per
inspection) — documented as the dividing line, not assumed away.

## Maintenance Mode

Laravel's own `php artisan down`/`up` — used only around a genuinely
breaking migration (rare, per the above). Super Admin's own infrastructure
operations (B16) remain fully auditable regardless of maintenance mode state
— maintenance mode is never used as a tenant-security bypass (Non-
Negotiable).

## Hostinger Compatibility

**REQUIRES EXTERNAL/ENVIRONMENT VERIFICATION** (Module 20's own explicit
category) — this Claude App sandbox cannot verify current Hostinger plan
capabilities (queue-worker/cron/SSH availability varies by plan tier). The
architecture itself imposes no Hostinger-specific coupling (confirmed by
inspection: storage/queue/cache all use Laravel's own provider-neutral
abstractions).

## VPS Readiness (Ubuntu 24.04 LTS)

**DOCUMENTED + templated**: every config template in `deploy/` targets this
exact baseline. Deployment user is non-root (`umartechy`, matching the
Dockerfile's own `USER umartechy` directive for consistency between Docker
and bare-VPS deployment).

## Docker Readiness

**IMPLEMENTED** (architecture-ready, NOT mandatory): `Dockerfile` (multi-
stage: Node frontend build → PHP-FPM runtime, non-root user, no dev
dependencies), `docker-compose.yml` (local/staging parity only). **NOT
EXECUTED — ENVIRONMENT LIMITATION**: no Docker daemon exists in this
sandbox to build or run either file.
