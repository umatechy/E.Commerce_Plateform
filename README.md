# Umar Techy E-Commerce Platform

Core Multi-Tenant E-Commerce SaaS. Basic / Business / Premium are entitlement tiers of
**one** codebase — never separate applications (Master Index non-negotiable rule).

## Status

Phases B0–B26 are built. As of Phase B21 the platform has been **executed
against a real runtime** (PHP 8.4, MySQL 8.0, Redis 7, Node 22): migrations
run cleanly, the full test suite passes (876 backend tests, 32 frontend
tests), PHPStan level 5 is clean, and the frontend lints and builds. Phase
B22 added Module 32 (Security, Audit & Compliance): a tamper-evident,
per-store audit trail, customer data export/erasure, security headers and
a password policy. Phase B23 added Module 29 (Billing, Invoices &
Renewals): package prices, invoices, an hourly renewal and dunning engine,
payments and a revenue summary. Phase B24 added Module 05 (Storefront): the
shopper-facing store at `/shop/{slug}` and on custom domains (catalog,
search, product pages, cart, guest checkout), server-rendered SEO, a public
storefront API, product images and the store launch flow. Phase B25 added
storefront customer accounts: sign-in with an HttpOnly session cookie, order
history, an address book, wishlist, profile and password management, and
password reset. Phase B26 added Module 34 (Support): a storefront contact
form with private guest links, requests in the customer account, the
store team's inbox with priorities, service levels, assignment and
internal notes, and a channel from merchants to the platform's support
staff. See `docs/checkpoints/checkpoint-b26.md` for the latest checkpoint
and what still needs a real deployment target.

## Stack

Laravel 12 (PHP 8.3+) · React + TypeScript + Inertia.js · Tailwind CSS + shadcn/ui ·
MySQL 8.0+ · Redis · Laravel Sanctum · Laravel Reverb · GitHub Actions · Nginx ·
Ubuntu 24.04 LTS. See `docs/adr/` for the implementation-level decisions this stack is
built on.

## Local Setup

Requirements: PHP 8.3+ (with pdo_mysql, redis, mbstring, intl), Composer,
MySQL 8.0+, Redis, Node 22.

```bash
composer install
cp .env.example .env
php artisan key:generate
npm install

# Create the database named by DB_DATABASE, set DB_USERNAME/DB_PASSWORD
# and REDIS_* in .env, then:
php artisan migrate
php artisan db:seed          # permissions, packages, default theme

npm run dev                  # Vite dev server
php artisan serve            # Laravel dev server
```

For the staff SPA login, the host you browse from (e.g. `localhost:8000`)
must be listed in `SANCTUM_STATEFUL_DOMAINS`.

Background processes: `php artisan queue:work` and the scheduler
(`php artisan schedule:work` locally, cron in production — see
`deploy/crontab.example`).

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

```bash
php artisan test     # PHPUnit feature suite — needs the MySQL test database from phpunit.xml
composer stan        # PHPStan / Larastan, level 5
npm run lint
npm run test         # Vitest
npm run build
```

Tests run on MySQL (not SQLite): the code relies on MySQL-only SQL. Create
the `umartechy_ecommerce_test` database (or override `DB_DATABASE`) before
running them. Playwright E2E is scaffolded only. See
`docs/testing/tenant-isolation-tests.md` for the tenant-isolation suite.

## Documentation Map

- `docs/adr/` — Architecture Decision Records (ADR-001–005), all `PROPOSED`.
- `docs/architecture/` — foundation structure plus one architecture note per phase (B1–B26).
- `docs/database/` — schema foundation, ADR-003 conventions applied.
- `docs/testing/` — how to run the suite and what the tenant-isolation tests cover.
- `docs/development/` / `docs/security/` — per-phase inspection findings and security reviews.
- `docs/checkpoints/` — development checkpoints per milestone.
