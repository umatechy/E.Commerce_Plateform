# Phase B20 — Step 1: Inspection + Gap Analysis (Hosting & Infrastructure Management: Module 20)

## Critical Environment Constraint — Stated Upfront

Same constraint as B19: this Claude App sandbox has no real Ubuntu server,
Nginx, PHP-FPM, MySQL, Redis, S3, Reverb, or CI runner. B20 is explicitly
scoped as ARCHITECTURE + APPLICATION INTEGRATION + DEPLOYMENT READINESS +
CONFIGURATION + DOCUMENTATION. Every "executed" claim below is qualified;
anything requiring real infrastructure is marked `NOT EXECUTED —
ENVIRONMENT LIMITATION`.

## Gap Analysis (A/B/C/D/E Classification)

### A. Existing and reusable (found by inspection, not rebuilt)

- **CI/CD**: `.github/workflows/ci.yml` — already mature since "Milestone-0":
  a `backend` job (PHP 8.3, MySQL 8.0 + Redis 7 service containers,
  `composer stan` via PHPStan/Larastan, `php artisan migrate --force`,
  `php artisan test`) and a `frontend` job (Node 22, lint, Vitest, build).
  Playwright E2E is deliberately deferred (documented in the file's own
  comment). **Not rebuilt** — extended only where B20 genuinely adds new
  scope (see "C" below).
- **Environment configuration**: `.env.example` — already documents DB
  (MySQL, ADR-001/003-referenced), Cache/Queue/Session (Redis, ADR-001
  tenant-prefixed keys), Sanctum (ADR-002), S3-compatible object storage
  (`AWS_*` vars, provider-neutral via `AWS_ENDPOINT`), Search (Scout,
  Meilisearch-ready), Reverb, Mail. **Not rebuilt.**
- **`.gitignore`**: already excludes `.env`, `.env.backup`, `.env.production`,
  `/storage/*.key`, `auth.json` — no secret-leakage gap found.
- **B17 `ConfigService`**: application configuration remains fully
  authoritative there; B20 does not touch it.
- **B19 backup domain**: `BackupService`/`RestoreService`/storage adapter
  remain fully authoritative; B20 only documents the INFRASTRUCTURE
  dependencies they need (a real MySQL host, a real filesystem/S3 disk,
  scheduler/queue execution) without duplicating any of their logic.
- **B14 domain resolution**: `DomainResolverService`/`ResolveTenantContext`
  remain fully authoritative; B20 documents the Host-header flow
  (`HTTP Host → web server → application → DomainResolverService →
  TenantContext`) without touching any of it.
- **B16 Super Admin platform-global route group**: reused unchanged for the
  new infrastructure-health oversight endpoint (see "C").
- **Laravel's own scheduler** (`routes/console.php`): already lists B10/B11/
  B19's scheduled commands; B20 adds no second scheduler.
- **Laravel's own queue system**: already used by every job since B7; B20
  adds no second queue system, only documents worker/Supervisor deployment.

### B. Existing but incomplete

- None identified as genuinely "partially built" — every infrastructure
  concern Module 20 names either already has a mature B0-era foundation (A)
  or has no code-level artifact at all yet (C).

### C. Missing and required — implemented this milestone

- **Health/readiness check endpoint + command**: no health check of any
  kind exists anywhere in B0-B19. Implemented: `InfrastructureHealthService`
  (DB connectivity, cache read/write round-trip, storage disk writability,
  scheduler freshness via B19's own `backups:expire` last-run marker),
  `GET /api/v1/public/health` (safe, minimal, no secrets — Module 20 §30
  "must not expose secrets"), and a Super-Admin-only detailed view reusing
  B16's platform-global group.
- **Docker readiness**: no `Dockerfile`/`docker-compose.yml` exists anywhere.
  Implemented: a production-shaped multi-stage `Dockerfile` (PHP-FPM +
  Composer + Node build stage) and a `docker-compose.yml` for LOCAL/staging
  parity (app, MySQL, Redis, a queue-worker service, a scheduler service) —
  architecture-ready, not mandatory, per Module 20's own "Docker is
  architecture-ready but not mandatory on day one."
- **Nginx configuration template**: none exists. Implemented:
  `deploy/nginx/umartechy.conf.example` (Laravel-correct routing, PHP-FPM
  upstream, `.env`/`.git`/storage/backup-artifact denial rules, upload/
  request-size limits, security headers, HTTPS redirect).
- **PHP-FPM pool template**: none exists. Implemented:
  `deploy/php-fpm/umartechy-pool.conf.example`.
- **Supervisor configuration for queue workers**: none exists. Implemented:
  `deploy/supervisor/umartechy-worker.conf.example`.
- **Deployment guide/checklist**: none exists. Implemented as documentation
  (see checkpoint's linked docs).
- **Environment-variable classification**: `.env.example` exists but does
  not classify which variables are public/private/infrastructure-credential/
  secret. Implemented as documentation (`docs/architecture/b20-secrets-and-environments.md`).

### D. Infrastructure-runtime verification only (documented, never executed here)

Real Ubuntu/Nginx/PHP-FPM/MySQL/Redis/S3/Reverb/SSL provisioning and
verification; real CI pipeline execution against a live runner; real
Docker build/run; real deployment/rollback execution; real load/performance
testing; a genuine disaster-recovery rehearsal (B19's own already-named gap,
not re-invented here). Full checklist in the checkpoint.

### E. Explicitly out of scope

Actual Hostinger/VPS account provisioning; actual Cloudflare/DNS record
changes; actual SSL certificate issuance; actual production secrets
generation; converting the platform to microservices (explicitly forbidden
by this milestone's own Non-Negotiable list); a hosting-billing integration
(Module 29's own domain — not duplicated here, since no concrete
requirement for one is given).

## Infrastructure Responsibility Matrix (Module 20 Phase 3)

| Layer | Owns | Does NOT Own |
|---|---|---|
| Application (Laravel) | Business logic, authorization, tenant resolution, all domain services (B1-B19) | Server provisioning, TLS termination, DNS |
| Database (MySQL) | Durable relational state | Business rules, caching |
| Cache/Queue (Redis) | Ephemeral cache, queue transport | Authoritative business data (Non-Negotiable — Redis must never become the source of truth) |
| Scheduler (Laravel `Schedule`) | Triggering existing artisan commands on a timer | The command's own business logic |
| Web Server (Nginx) | Routing, static asset serving, TLS termination (or proxying to it), sensitive-file denial | Application logic, tenant authorization |
| PHP-FPM | PHP process execution | Business logic |
| Object Storage (S3-compatible) | Durable file/backup artifact storage | Access authorization (the application decides who may read/write a given key) |
| CDN/WAF (Cloudflare) | Edge caching, DDoS/WAF, DNS | Tenant identity/authorization (Non-Negotiable — never the source of truth for tenant identity) |
| DNS | Name resolution | Application routing logic |
| SSL/TLS | Transport encryption | Application-level authentication |
| Monitoring/Logging | Observability | Business decisions |
| Backup (B19) | Backup/restore business logic and lifecycle | The actual disk/server the artifact lives on (B20's job) |
| Deployment System | Getting new code running safely | Runtime business behavior |

## Deployment Target Model (Module 20 Phase 4)

- **Initial target**: Hostinger-compatible managed hosting or VPS.
- **Future target**: Ubuntu 24.04 LTS VPS (self-managed or equivalent
  provider), matching this project's already-approved infrastructure
  baseline (Project Bible / Technical Architecture).
- **Future scaling**: horizontal PHP-FPM/worker scaling behind a load
  balancer, managed MySQL/Redis, S3-compatible object storage at any
  provider — no code in this platform is hard-coded to Hostinger
  specifically; `AWS_ENDPOINT`/`FILESYSTEM_DISK` already make storage
  provider-neutral (an existing B0-era decision, confirmed by inspection).

## Server Requirements (Module 20 Phase 5)

Ubuntu 24.04 LTS, PHP 8.3+ (`mbstring, pdo_mysql, redis, gd, zip, bcmath,
intl` — matching the CI job's own extension list plus the two this
platform's payment/i18n code paths need), Composer 2.x, Node 22 (matching
CI), MySQL 8.0+, Redis 7+, Nginx, a process manager (Supervisor or systemd)
for queue workers and Reverb, cron (for Laravel's scheduler), Git. **CPU/RAM
sizing is REQUIRES CAPACITY VALIDATION** — Module 20 defines no numeric
target, and none is invented here.
