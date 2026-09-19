# Phase B1 — Identity, Authentication, Authorization & Tenancy Architecture

## Authentication Flow (ADR-002 Surface A — Sanctum)

**Registration:** `POST /api/v1/auth/register` → `AuthController::register()` creates
`User` + `Store` + default roles (via `StoreObserver`) + owner membership, atomically,
inside one `DB::transaction()`. `Auth::login()` + session regeneration follow
immediately — the newly registered owner is authenticated in the same request.

**Login:** `POST /api/v1/auth/login` → `LoginRequest::authenticate()` (rate-limited,
5 attempts per email+IP, Laravel's standard `Lockout` event) → `Auth::attempt()` →
session regeneration.

**Logout:** `POST /api/v1/auth/logout` (authenticated) → session invalidation + token
regeneration.

**Session identity:** `GET /api/v1/auth/me` → `UserResource` (explicit allow-list,
never a raw model).

No JWT, no OAuth code exists anywhere in this milestone's additions — verified by
inspection, consistent with ADR-002's "Sanctum is canonical now; OAuth/JWT is
future/conditional, Module 31 scope only."

## Authorization Flow

Every sensitive action passes through **two independent layers**, per ADR-001's
defense-in-depth principle, applied here for the first time to a concrete resource
(Role):

1. **Tenant scoping** (`BelongsToTenant` global scope + Laravel route-model binding) —
   a cross-tenant `Role` ID resolves to 404 before any Policy method ever runs.
2. **Policy** (`RolePolicy`, registered explicitly in `AppServiceProvider` — App\Domain\*
   models do not use Laravel's default auto-discovery convention, so this registration
   is required, not optional) — checks permission (via `Role`/`Permission` tables) or
   Owner-role membership.

`RoleController` calls `Gate::forUser($request->user())->authorize(...)` explicitly for
every method — no endpoint skips this.

## Tenant Context Flow

`ResolveTenantContext` middleware (unchanged from B0, still correct) resolves
`TenantContext` from the authenticated user's **verified active store membership**,
which in B1 is now correctly backed by `StoreSwitcher` (session-verified, supports
multi-store users — see `docs/development/b1-inspection-findings.md` item B/C for what
was fixed and why).

`TenantContext` is now correctly bound `scoped()` in `AppServiceProvider` (the B0
critical gap — see inspection findings item C) so the same resolved instance is shared
for the lifetime of one request/job, not silently re-constructed empty on each
`app(TenantContext::class)` call.

## User / Store Relationship

```
User ──< store_user >── Store
          │
          └── role_id → Role (store-scoped)
```

A `User` row is platform-level identity; a `Store` row is the tenant; `store_user` is
the only table connecting them, carrying both the assigned `Role` and a `status`
(active/suspended/revoked) — checked on every tenant-switch and every
`ResolveTenantContext` resolution, never assumed once granted.

## Role / Permission Model

- `permissions` — platform-level catalog (shared keys like `roles.view`), seeded once
  via `PermissionSeeder` (idempotent).
- `roles` — tenant-owned; every new `Store` gets `owner`/`manager`/`staff` seeded
  automatically by `StoreObserver` at creation time (event-driven, not a global
  destructive reseed).
- `permission_role` — pivot; tenant isolation is inherited from `roles.store_id`, so
  this pivot never needs its own `store_id` column.

## Super Admin Boundary

`users.platform_role` is structurally separate from any store-scoped `Role` — no
store-scoped role, however permissive, can ever grant platform access, because
`isPlatformStaff()` reads a different column entirely. Cross-tenant access
(`/api/v1/super-admin/stores/{store}/impersonate`) requires **two independent checks**
(`can:super-admin.impersonate` Gate + `EnsureSuperAdminImpersonation` middleware) before
`TenantContext` is ever resolved to `impersonation = true`, and every impersonation
start is written to the `audit` log channel.

## API Endpoints Added in B1

| Method | Path | Auth | Notes |
|---|---|---|---|
| POST | `/api/v1/auth/register` | none | rate-limited implicitly via unique-email constraint + validation |
| POST | `/api/v1/auth/login` | none | rate-limited (5/min per email+IP) |
| POST | `/api/v1/auth/logout` | Sanctum | |
| GET | `/api/v1/auth/me` | Sanctum | |
| POST | `/api/v1/store/switch` | Sanctum | server-verifies membership before accepting |
| GET/POST/PUT/DELETE | `/api/v1/roles[/{role}]` | Sanctum + RolePolicy | first concrete tenant-owned resource CRUD |
| GET | `/api/v1/super-admin/stores/{store}/impersonate` | Sanctum + Gate + middleware | ADR-001 Layer 7 |

## Frontend Authentication Structure

`Pages/Auth/Login.tsx`, `Pages/Auth/Register.tsx` — Inertia `useForm`, submit to the
API endpoints above, server validation errors surfaced automatically. No client-side
"is this a valid password" logic beyond native HTML input types — validation is
server-owned. `Layouts/AuthenticatedLayout.tsx` reads user/store from
`HandleInertiaRequests`-shared props (read-only, display-only — never an authorization
source). `Components/{Loading,Empty,Error}State.tsx` — reusable primitives for every
future module's pages.
