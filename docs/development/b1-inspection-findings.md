# Phase B1 — Step 1: Inspection of Existing B0 Implementation

Performed before writing any new B1 file, per this milestone's instruction.

## A. Reusable As-Is

- `App\Domain\Tenancy\Support\TenantContext` — design correct (ADR-001 Layer 2).
- `App\Http\Middleware\ResolveTenantContext` — resolution priority correct.
- `App\Domain\Tenancy\Support\BelongsToTenant` — global-scope trait correct.
- `App\Http\Middleware\EnsureSuperAdminImpersonation` — audit-log call correct.
- `Store`, `Role`, `Permission`, `Package`, `PackageEntitlement`, `Subscription`,
  `OutboxEvent` models and their migrations — schema and relationships correct, reused
  without modification.
- `EntitlementService`, `BaseTenantPolicy` — reused as the foundation B1's concrete
  Policy/authorization code builds on.

## B. Incomplete (extended in B1, not rewritten)

- `User::activeStoreId()` returned the *first* store with an `active` pivot status —
  correct only for single-store users. For genuine multi-store support (this prompt:
  "the architecture must remain capable of supporting multiple store memberships"),
  this needed a real **session-backed "current store" selection**, verified server-side
  against membership on every switch. Extended via a new `StoreSwitcher` service (see
  below) rather than rewriting the model method's signature.
- No `Tests\TestCase` base class existed — the B0 test files referenced it but it was
  never created. This is a genuine gap (tests could not have run even if a runtime were
  available). Created now.
- No concrete `RolePolicy`, `RoleController`, or `/api/v1/roles` routes existed — the B0
  `TenantIsolationTest` suite was written test-first against routes that did not exist
  yet (documented at the time as intentional). Implemented now.

## C. Incorrect (fixed)

- **`TenantContext` was never bound as a singleton in the service container.** Because
  it is a stateful object (`ResolveTenantContext` middleware sets it once per request;
  every downstream global scope/service reads it), without an explicit
  `$this->app->singleton(TenantContext::class, ...)` binding, Laravel's default
  auto-resolution would construct a **new, empty instance on every `app(TenantContext::class)`
  call**, meaning the resolved tenant would silently fail to propagate from the
  middleware to the rest of the request — a genuine tenant-isolation correctness bug,
  not just an omission. **Fixed** in the new `AppServiceProvider` (`singleton()` binding,
  scoped to reset cleanly between requests and between queue-worker job executions).

## D. Duplicated

None found.

## E. Missing (implemented in this milestone)

- Authentication: `AuthController` (register/login/logout/me), `RegisterRequest`,
  `LoginRequest`.
- API response safety: `UserResource`, `StoreResource`, `RoleResource` (no password
  hashes, no tokens, no internal fields exposed).
- Authorization: concrete `RolePolicy`, `AppServiceProvider`-registered policy mapping
  (models live under `App\Domain\*`, not Laravel's default `App\Models`/`App\Policies`
  convention, so **explicit** `Gate::policy()` registration is required — auto-discovery
  would silently fail to apply these policies, another correctness gap avoided here).
- Role management API: `RoleController` (CRUD), tenant-safe by construction (global
  scope + policy).
- Tenant switching: `StoreSwitchController`, `StoreSwitcher` service.
- Super Admin: `SuperAdminController` (impersonation entry point the B0 test suite
  already assumed), `SuperAdminAccessPolicy`.
- Seeding: `PermissionSeeder` (platform-level catalog), `StoreObserver` (seeds a
  store's default role set — Owner/Manager/Staff — at store-creation time, not via a
  destructive global seeder run).
- Frontend: `Login.tsx`, `Register.tsx`, `AuthenticatedLayout.tsx`.
- Tests: authentication, authorization, tenant-switching, and extended tenant-isolation
  coverage (role/user manipulation across stores).

## F. Security Risks Identified

1. **TenantContext missing singleton binding (see C)** — the most serious finding;
   fixed.
2. **No policy auto-discovery for `App\Domain\*` models** (see E) — would have caused
   every future Policy to silently never be applied (Laravel falls back to "no policy
   found" rather than erroring), a fail-open risk. Fixed by explicit registration in
   `AppServiceProvider`, with a startup-time assertion pattern documented in
   `docs/security/b1-security-review.md`.
3. **`User::activeStoreId()` single-store assumption** (see B) — not itself a leak
   (still scoped to the user's own memberships), but a functional gap that would have
   forced an insecure workaround later (e.g. trusting a client-supplied store ID
   per-request) if not fixed properly now with a verified session-based switch.

None of A–F required deleting or resetting existing work; all fixes are additive or
targeted, per this milestone's "do not destroy existing implementation" rule.
