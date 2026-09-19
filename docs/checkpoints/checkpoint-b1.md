============================================================
PHASE B1 CHECKPOINT
============================================================

Phase:
Development Phase B

Milestone:
B1 — Identity, Authentication, Authorization & Tenancy

Status:
Implemented in Claude App environment as far as this environment allows. Runtime
execution deferred to VS Code phase (no PHP/Composer/MySQL/Redis/network available
here — confirmed at Milestone-0 preflight and unchanged since).

Completed:
- Step 1 inspection of existing B0 implementation performed before any change
  (docs/development/b1-inspection-findings.md) — found and fixed 3 genuine B0 gaps
  (TenantContext container binding, missing Policy registration, single-store
  activeStoreId() assumption), plus a missing Tests\TestCase base class.
- User/Store/Role/Permission identity layer completed and wired to real endpoints.
- Sanctum authentication (register/login/logout/me) implemented end-to-end.
- Server-side authorization (RolePolicy, two-layer tenant-scope + policy model)
  implemented and applied to the first concrete tenant-owned resource (Role).
- Tenant context foundation corrected (scoped() container binding) and extended
  (StoreSwitcher — verified, session-backed multi-store switching).
- Super Admin boundary implemented with two independent enforcement points (Gate +
  middleware) and audit logging.
- API v1 identity endpoints implemented per ADR-005.
- Inertia/React authentication UI (Login, Register) + reusable authenticated shell
  (AuthenticatedLayout) + Loading/Empty/Error state components implemented.
- Default role/permission seeding strategy implemented (event-driven StoreObserver +
  idempotent PermissionSeeder) — deterministic, repeatable, non-destructive.
- 23 test methods written across 4 Feature test files (Authentication,
  RoleAuthorization, TenantSwitching, and extended TenantIsolation).
- Focused security review performed; 6 issues found and fixed within B1 scope, 2
  documented for later modules (docs/security/b1-security-review.md).
- Documentation created/updated across docs/development/, docs/architecture/,
  docs/security/, docs/checkpoints/.

Database:
No new migrations required — B0's identity tables (users, stores, store_user, roles,
permissions, permission_role) were already sufficient for B1's scope; reused as-is
per this milestone's "if a required table/column already exists, reuse it" rule.
No destructive changes made; no database reset performed.

Authentication:
Laravel Sanctum (ADR-002 Surface A) — SPA cookie-session mode. Endpoints: POST
/api/v1/auth/register (rate-limited 5/min), POST /api/v1/auth/login (rate-limited
10/min, 5-attempt lockout via LoginRequest), POST /api/v1/auth/logout, GET
/api/v1/auth/me. No JWT, no OAuth code introduced anywhere (verified by inspection).
Passwords hashed via Eloquent cast, never logged, never returned in any API Resource.

Authorization:
Two-layer model: (1) BelongsToTenant global scope + route-model binding make a
cross-tenant resource unreachable (404) before any Policy runs; (2) RolePolicy
(permission-key check or Owner-role check, both scoped to the user's own active
store membership only). Policies for App\Domain\* models require EXPLICIT
registration in AppServiceProvider (Laravel's default auto-discovery convention does
not apply to this namespace) — this was verified missing in B0 and fixed in B1.

Tenant Context:
ResolveTenantContext middleware (B0, still correct) + TenantContext container (B0
design correct, but was missing its scoped() service-container binding — a real
correctness bug, fixed in B1's AppServiceProvider). User::activeStoreId() now
delegates to the new StoreSwitcher service, which verifies session-persisted store
selection against live membership on every read, correctly supporting users with
memberships in more than one store (B0's version silently assumed single-store).

User:
Platform-level identity (App\Domain\Identity\Models\User), Sanctum HasApiTokens,
explicit $fillable and $hidden, password hashed cast, isPlatformStaff() reads a
structurally separate platform_role column never reachable via any store-scoped Role.

Store:
Unchanged from B0 (App\Domain\Tenancy\Models\Store) — immutable identity, no store_id
column on itself, soft-deletable. Reused as-is.

Membership:
store_user pivot (B0 table, reused) — status (active/suspended/revoked) checked on
every StoreSwitcher resolution and every tenant-switch request; a suspended/revoked
membership cannot gain or retain tenant context, tested explicitly.

Roles:
Tenant-owned (App\Domain\Identity\Models\Role, BelongsToTenant). StoreObserver seeds
Owner/Manager/Staff automatically for every new store at creation time. is_system
roles cannot be edited or deleted by anyone (including Owner), closing a path to
repurposing a system role's implicit privileges.

Permissions:
Platform-level catalog (App\Domain\Identity\Models\Permission), seeded idempotently
via PermissionSeeder. 9 permission keys defined for B1 scope (roles.*, users.*, plus
forward-declared products.*/orders.* keys StoreObserver's Manager/Staff roles already
reference, ready for Phase B3/B5 to use without a second seeding pass).

Policies:
RolePolicy (concrete, extends BaseTenantPolicy) — first concrete Policy in the
codebase. SuperAdminAccessPolicy (Module 30 boundary, minimal B1-scope gate).

Super Admin:
users.platform_role structurally distinct from any store-scoped Role. Impersonation
route (/api/v1/super-admin/stores/{store}/impersonate) protected by TWO independent
checks (can:super-admin.impersonate Gate + EnsureSuperAdminImpersonation middleware)
plus mandatory audit-channel logging before the controller runs. No hidden universal
bypass exists (verified by inspection — no code path resolves TenantContext to
platform/impersonation mode outside this one, doubly-guarded route).

API:
POST /api/v1/auth/register, POST /api/v1/auth/login, POST /api/v1/auth/logout, GET
/api/v1/auth/me, POST /api/v1/store/switch, GET/POST/PUT/DELETE /api/v1/roles[/{role}],
GET /api/v1/super-admin/stores/{store}/impersonate. Every endpoint: validated
(FormRequest), authenticated (auth:sanctum where required), authorized (Policy/Gate
where applicable), tenant-scoped (BelongsToTenant), and returns an explicit API
Resource (never a raw model).

Frontend:
resources/js/Pages/Auth/{Login,Register}.tsx (Inertia useForm, server-owned
validation), Layouts/AuthenticatedLayout.tsx (reusable shell, reads
HandleInertiaRequests-shared auth/activeStore props — display-only, never an
authorization source), Components/{Loading,Empty,Error}State.tsx (reusable
primitives for future modules). No full admin dashboard built (correctly out of
scope).

Tests Created:
29 test methods total across the suite (verified by direct count, not estimated):
- tests/Feature/Auth/AuthenticationTest.php — 8 methods (new this milestone)
- tests/Feature/Authorization/RoleAuthorizationTest.php — 6 methods (new this milestone)
- tests/Feature/Tenancy/TenantSwitchingTest.php — 4 methods (new this milestone)
- tests/Feature/Tenancy/TenantIsolationTest.php — 9 methods (7 carried from B0 + 2 new
  this milestone; 1 of the 7 remains markTestIncomplete pending a Phase B5+ concrete job)
- tests/Feature/Tenancy/SuperAdminCrossTenantAccessTest.php — 2 methods (B0, unchanged)
Plus 4 model factories (Store, User, Role, Permission) supporting them.

Tests Executed:
NONE.

Runtime Verification:
NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION. No PHP, Composer, MySQL, or
Redis runtime is available in this Claude App sandbox; no outbound network access to
Packagist either. Every one of the 29 test methods, every migration, PHPStan, ESLint,
npm build, and the CI workflow itself have been authored and statically reasoned about,
never executed. A lightweight Node.js-based brace/parenthesis balance check was run
across all new PHP files as an additional (non-substitute) sanity pass — no mismatches
found.

Security Review:
Performed (docs/security/b1-security-review.md) — 19-item checklist reviewed
end-to-end. 6 issues found and fixed within B1 scope: TenantContext container binding,
missing Policy registration, single-store activeStoreId() assumption, missing
Tests\TestCase, missing Sanctum EnsureFrontendRequestsAreStateful registration, missing
rate limit on /auth/register. 2 items documented as correctly deferred to later modules
(Module 32 structured audit logging for tenant CRUD; production
SANCTUM_STATEFUL_DOMAINS/SESSION_DOMAIN values).

Documentation:
docs/development/b1-inspection-findings.md, docs/architecture/b1-identity-auth.md,
docs/security/b1-security-review.md, this checkpoint. Project Bible, SRS, and ADR
status were not modified, per instruction.

Known Limitations:
- Nothing in this milestone has been executed against a real runtime.
- Audit logging exists only for Super Admin impersonation, not yet for ordinary
  tenant CRUD (correctly deferred to Module 32).
- Production-specific Sanctum stateful-domain/session-domain configuration values
  remain placeholders in .env.example, as they must (real values are
  environment-specific, never committed).
- Password reset / email verification flows are not part of B1's defined scope
  (SRS Identity & Authentication Requirements do not require them for this
  milestone) and were not built — flagged rather than silently omitted.

Deferred VS Code Verification:
1. composer install / npm install (first real dependency resolution).
2. php artisan migrate against a real MySQL instance (B0 migrations, unchanged).
3. php artisan db:seed (PermissionSeeder).
4. php artisan test — all 29 test methods (20 new this milestone + 9 carried forward),
   for real PASS/FAIL results.
5. composer stan (PHPStan/Larastan), npm run lint (ESLint), npm run build.
6. Manual verification that EnsureFrontendRequestsAreStateful + real
   SANCTUM_STATEFUL_DOMAINS values actually produce a working authenticated SPA
   session in a real browser.
7. CI workflow (.github/workflows/ci.yml) triggered for the first time on an actual
   GitHub Actions runner.

Next Milestone:
Phase B2 — Packages, Subscriptions & Entitlements. B1 exit criteria are met: identity,
authentication, authorization, tenant context, role/permission foundation, Super Admin
boundary, API foundation, and authenticated frontend shell all exist; B1 documentation
and tests exist. Proceeding autonomously into B2 per this milestone's instruction does
not require a new small prompt — will begin with Step 1 (inspect existing B0/B1
Package/Subscription/Entitlement code already present) before any B2 change, consistent
with this milestone's own working pattern.
