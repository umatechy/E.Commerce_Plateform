# Umar Techy E-Commerce Platform

Core Multi-Tenant E-Commerce SaaS. Basic / Business / Premium are entitlement tiers of
**one** codebase — never separate applications (Master Index non-negotiable rule).

## Status

Foundation (Phase B0) + start of Phase B1 (Identity/Tenancy), built inside the Claude
App environment. **Not yet executed against a real PHP/MySQL/Redis runtime** — see
`docs/checkpoints/checkpoint-b0.md` for the honest execution status of every artifact
below before relying on any of it.

## Stack

Laravel 12 (PHP 8.3+) · React + TypeScript + Inertia.js · Tailwind CSS + shadcn/ui ·
MySQL 8.0+ · Redis · Laravel Sanctum · Laravel Reverb · GitHub Actions · Nginx ·
Ubuntu 24.04 LTS. See `docs/adr/` for the implementation-level decisions this stack is
built on.

## Local Setup (to be verified in VS Code phase)

```bash
composer install
cp .env.example .env
php artisan key:generate
npm install

# Configure DB_* and REDIS_* in .env, then:
php artisan migrate

npm run dev        # Vite dev server
php artisan serve  # Laravel dev server
```

## Architecture

See `docs/architecture/foundation.md`. Short version: Modular Monolith, domain-bounded
under `app/Domain/{Tenancy,Identity,Packages,Events,...}`, one shared MySQL database
with strict, layered tenant isolation (`docs/adr/ADR-001-tenant-resolution-and-isolation.md`).

## Multi-Tenancy Rules (non-negotiable)

- Every tenant-owned model uses `App\Domain\Tenancy\Support\BelongsToTenant`.
- Tenant context is resolved server-side only (`App\Http\Middleware\ResolveTenantContext`) —
  never trusted from client input. See ADR-001.
- Store/Tenant ID never changes across a package upgrade/downgrade.

## Security Rules

- All authorization is server-side (Policies + `EntitlementService`); never trust
  frontend permission/role checks.
- Sanctum is the only authentication mechanism currently implemented (ADR-002).
- No secrets are committed; see `.env.example` for every required variable.

## Testing

`php artisan test` (PHPUnit/Feature), `npm run test` (Vitest), `npm run test:e2e`
(Playwright — minimal scaffold only per Milestone-0 Step 0.9). See
`docs/testing/tenant-isolation-tests.md` for the mandatory tenant-isolation suite and
its current (unexecuted) status.

## Documentation Map

- `docs/adr/` — Architecture Decision Records (ADR-001–005), all `PROPOSED`.
- `docs/architecture/` — foundation structure and what Phase B0 does/does not cover.
- `docs/database/` — schema foundation, ADR-003 conventions applied.
- `docs/testing/` — test status, honestly reported.
- `docs/checkpoints/` — development checkpoints per milestone.
