# Phase B0 — Foundation Architecture

**Environment note:** Built inside the Claude App environment (no local PHP/Composer/
MySQL/Redis runtime available). All artifacts below are source-ready but
**NOT EXECUTED — CLAUDE APP ENVIRONMENT LIMITATION**. They are designed for
straightforward verification once opened in VS Code per the deferred runtime phase.

## Project Structure

```
app/
  Domain/                     <- business logic, organized by bounded context
    Tenancy/                  <- Store (tenant), TenantContext, BelongsToTenant, ADR-001
      Models/
      Support/
      Exceptions/
    Identity/                 <- User, Role, Permission, Policies (Module 02)
      Models/
      Policies/
    Packages/                 <- Package, Subscription, Entitlement (Module 04)
      Models/
      Services/
      Exceptions/
    Events/                   <- OutboxEvent, dispatcher/consumer jobs (ADR-004)
      Models/
      Support/
      Jobs/
  Http/
    Middleware/                <- ResolveTenantContext, EnsureSuperAdminImpersonation
    Controllers/Api/V1/        <- populated starting Phase B3
  Console/Commands/            <- outbox:publish
config/
  tenancy.php
  outbox.php
database/
  migrations/                  <- Milestone-0 minimum schema only (ADR-003)
  factories/
routes/
  web.php, api_v1.php, api_v1_public.php, api_dev_v1.php (reserved), console.php
tests/
  Feature/Tenancy/             <- mandatory tenant-isolation regression suite (ADR-001 §16)
resources/js/                  <- Inertia/React/TypeScript (scaffolded next iteration)
.github/workflows/ci.yml
docs/
```

This directly implements the Modular Monolith requirement (Approved Stack, Bible §10):
clear domain boundaries (`app/Domain/{Tenancy,Identity,Packages,Events,...}`), no
microservices, and a structure that keeps future service extraction possible (each
`Domain/*` subtree has no inbound dependency from another except through its own
`Services`/public model classes).

## What Phase B0 Implements

| Item | Status |
|---|---|
| Tenant resolution middleware (ADR-001 Layer 1) | Implemented |
| TenantContext container (ADR-001 Layer 2) | Implemented |
| Global-scope tenant isolation (ADR-001 Layer 3) | Implemented (`BelongsToTenant` trait) |
| Super Admin cross-tenant context (ADR-001 Layer 7) | Implemented (middleware + audit log call) |
| Emergency/system context (ADR-001 Layer 9) | Contract established (`resolveToPlatform()`); concrete `platform_system` console command wiring deferred to the module that first needs it |
| Sanctum-only authentication (ADR-002) | Wired (`auth:sanctum` middleware); OAuth/JWT deliberately NOT scaffolded |
| Minimum foundation tables (ADR-003) | Implemented: stores, users, store_user, roles, permissions, permission_role, packages, package_entitlements, subscriptions, outbox_events |
| Transactional outbox (ADR-004) | Implemented: `OutboxEvent` model, `RecordsOutboxEvents` helper, `PublishOutboxEvents` dispatcher, `ConsumeOutboxEventJob` generic consumer contract |
| API versioning (ADR-005) | Implemented: `/api/v1/...`, `/api/v1/public/...` wired; `/api/dev/v1/...` prefix reserved, unwired |
| Entitlement enforcement (Module 04) | Implemented: `EntitlementService` (server-authoritative feature/usage-limit checks) |
| Tenant-isolation test suite | Written (not executed — see Testing docs) |
| CI pipeline | Written (not executed — no GitHub Actions runner triggered from this environment) |

## What Phase B0 Deliberately Does NOT Implement Yet

- Concrete commerce endpoints/controllers (Phase B3+).
- Concrete React/Inertia pages beyond a placeholder `Welcome` reference (frontend
  scaffolding to be extended alongside Phase B1 auth screens).
- Sanctum's own `personal_access_tokens` migration — this is normally published via
  `php artisan vendor:publish --tag=sanctum-migrations` in the VS Code phase; hand-writing
  a copy here would risk drifting from the actual package version.
- Developer API (Module 31) middleware/OAuth wiring — explicitly deferred per ADR-002.
- Domain-based storefront tenant resolution (Module 19) — the integration point is
  documented in `ResolveTenantContext` but the resolver itself is a later module.

## Next Milestone

Phase B1 continuation: complete Identity/Authentication screens (login, registration,
store-switching for multi-store users), Role/Permission seeders (default role set per
new Store), and the first concrete Policy classes wired to real controllers.
