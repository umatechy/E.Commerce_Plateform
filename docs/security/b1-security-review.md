# Phase B1 — Focused Security Review

Static/design-level review only — **NOT EXECUTED — DEFERRED TO VS CODE RUNTIME
VERIFICATION** for any dynamic scanning (no SAST/DAST tool was run; no penetration test
was performed). This is a manual, targeted review against this milestone's checklist.

| Item | Finding | Status |
|---|---|---|
| **Authentication** | Sanctum SPA session mode; `Auth::attempt()` uses hashed password comparison; no plaintext password ever stored (`password => 'hashed'` cast on `User`). | Reviewed — OK |
| **Authorization** | Two-layer model (tenant scope + Policy) applied to `Role`; `AppServiceProvider` explicit policy registration required and present. | Reviewed — OK, see note below |
| **IDOR** | ADR-001 Layers 3–4: cross-tenant `Role` ID → 404, not 403, via global scope; asserted in `TenantIsolationTest`. | Reviewed — OK |
| **Tenant isolation** | `TenantContext` singleton-binding bug (B0) fixed via `scoped()` — see inspection findings item C; this was a real, not hypothetical, isolation risk before the fix. | **Fixed this milestone** |
| **Role escalation** | `RolePolicy::isOwner()` checks the Owner role via `slug === 'owner'` on the user's **own active store membership only** — cannot be satisfied by an Owner role in a different store (query is scoped to `$user->activeStoreId()`). `is_system` roles (owner/manager/staff) cannot be edited or deleted by anyone, closing off a path to renaming/repurposing a system role's privileges. | Reviewed — OK |
| **Permission escalation** | Permission catalog is platform-level and centrally seeded (`PermissionSeeder`); a store cannot invent a permission key that grants access outside its own `permission_role` pivot — the pivot itself is tenant-scoped via `roles.store_id`. | Reviewed — OK |
| **Super Admin bypass** | Two independent enforcement points (`can:super-admin.impersonate` Gate + `EnsureSuperAdminImpersonation` middleware) — a bug in one does not remove the other. Every impersonation logged to the `audit` channel before the controller runs. | Reviewed — OK |
| **Mass assignment** | Every model uses explicit `$fillable`; `RegisterRequest`/`StoreRoleRequest` never accept or forward a client-supplied `store_id`/`role_id` for privilege fields — `AuthController`/`RoleController` set `store_id` exclusively via `BelongsToTenant`'s `TenantContext` auto-fill. Directly tested in `test_tenant_a_cannot_override_tenant_via_request_payload` (B0) and implicitly by `StoreRoleRequest`'s rule set (no `store_id` rule at all). | Reviewed — OK |
| **Password handling** | `Password::defaults()` on registration (Laravel's configurable minimum-strength ruleset); hashed via Eloquent cast, never logged (no `password` field appears in any `Log::` call in this milestone's code — verified by inspection). | Reviewed — OK |
| **Token handling** | No tokens are issued or stored client-side in this milestone (session-cookie only); `UserResource` never includes any token/secret field. | Reviewed — OK (N/A beyond session cookie, which is `httpOnly`/`secure` by Laravel's session config defaults — to be confirmed against actual `.env`/`config/session.php` values in the VS Code phase). |
| **Session handling** | Session regenerated on login (`session()->regenerate()`) and on logout (`invalidate()` + `regenerateToken()`) — standard Laravel session-fixation protection. | Reviewed — OK |
| **CSRF** | Sanctum's SPA cookie mode relies on Laravel's standard CSRF middleware (`VerifyCsrfToken`, part of the default `web` group) plus `EnsureFrontendRequestsAreStateful` on the `api` group. **Found missing during this review, fixed in this milestone**: `EnsureFrontendRequestsAreStateful` was not yet registered on the `api` middleware group in `bootstrap/app.php` — added (`prependToGroup('api', ...)`, ahead of `ResolveTenantContext` so Sanctum's stateful-domain check runs first). |
| **XSS** | React escapes all rendered text by default; no `dangerouslySetInnerHTML` used anywhere in this milestone's components (verified by inspection). | Reviewed — OK |
| **SQL injection** | No raw SQL/query-builder string concatenation anywhere in this milestone's code — every query goes through Eloquent's parameterized query builder. | Reviewed — OK |
| **Raw queries** | None introduced this milestone (ADR-001 Layer 5 has nothing to review yet). | N/A this milestone |
| **API exposure** | `UserResource`/`StoreResource`/`RoleResource` are explicit allow-lists; no controller returns a raw model. | Reviewed — OK |
| **Sensitive fields** | Verified `password`, `remember_token` never appear in any Resource's `toArray()`. | Reviewed — OK |
| **Error leakage** | Login/registration validation errors are Laravel's standard structured 422 envelope; no stack trace or internal exception message is returned to the client in this milestone's controllers (all exceptions thrown are caught by Laravel's default exception handler in non-debug mode — `APP_DEBUG=false` in production per `.env.example`, verify in deployment config). | Reviewed — OK, contingent on `APP_DEBUG=false` in production (outside this milestone's control) |
| **Audit boundaries** | Super Admin impersonation is audited; ordinary tenant CRUD (Role create/update/delete) is **not yet** written to a structured audit log in B1 — this is Module 32's dedicated concern and correctly deferred, but is flagged as **not yet implemented**, not silently assumed done. |

## Issues Found and Fixed Within B1 Scope

1. `TenantContext` missing `scoped()` container binding (B0) — **fixed**.
2. Missing explicit Policy registration for `App\Domain\*` namespace — **fixed** via
   `AppServiceProvider::boot()`.
3. `User::activeStoreId()` single-store-only assumption — **fixed** via `StoreSwitcher`.
4. Missing `Tests\TestCase` base class — **fixed**.
5. `EnsureFrontendRequestsAreStateful` not registered on the `api` middleware group —
   **fixed** in `bootstrap/app.php`.
6. `/api/v1/auth/register` had no rate limiting (only `/login` did) — **fixed**
   (`throttle:5,1`).

## Issues Documented for Later Modules (Outside B1 Scope)

1. Structured audit logging for ordinary tenant CRUD operations (Module 32) — not yet
   implemented; only Super Admin impersonation is currently audited.
2. `SANCTUM_STATEFUL_DOMAINS` / `SESSION_DOMAIN` values in `.env.example` are
   placeholders for local development; production values must be set correctly in the
   VS Code/deployment phase or the stateful-request check added in this review will
   reject legitimate SPA requests — noted so this isn't mistaken for a bug later.

None of the "found and fixed" items required deleting or resetting existing B0 work —
all were additive corrections, consistent with this milestone's development rules.
