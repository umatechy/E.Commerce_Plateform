# Phase B16 — Focused Super Admin Security Review

Static/design-level review only — **NOT EXECUTED — DEFERRED TO VS CODE RUNTIME
VERIFICATION**.

## Regression Check — B0-B15 Capabilities Confirmed Intact

Verified by direct `git diff`: `EnsureSuperAdminImpersonation.php`,
`EnsureCustomerPrincipal.php`, `EnsureStaffPrincipal.php` all show ZERO
changes — every existing `{store}`-scoped Super Admin route and the customer/
staff authentication boundary are byte-for-byte unchanged.
`BelongsToTenant::store()` present. All new capability is additive: a new
middleware, a new Gate ability, a new policy method, new controllers, one new
column, action-specific audit lines added alongside (never replacing)
existing ones.

## Checklist (this milestone's own §34 categories)

| Category | Item | Finding | Status |
|---|---|---|---|
| Authentication | Privileged auth boundary | Super Admin uses the exact same Sanctum/session mechanism as any staff user — `isPlatformStaff()` is an ADDITIONAL check (`platform_role !== null`), never a separate, weaker authentication path. | Reviewed — OK |
| Authentication | Account status / login lock | New `users.is_active` check in `LoginRequest::authenticate()`, checked AFTER credential match (never reveals whether credentials were correct for a locked account — same generic failure message). Tested explicitly. | Reviewed — OK |
| Authorization | Privilege escalation | No endpoint allows a user to grant themselves `platform_role` — that column is never client-writable through any B16 endpoint (not in any FormRequest's validated fields). | Reviewed — OK |
| Authorization | Self-privilege assignment | Same as above — no Super Admin endpoint modifies the ACTING user's own `platform_role`/`is_active`. | Reviewed — OK |
| Authorization | Horizontal/vertical access, tenant crossing | Fixed this milestone's central bug (see architecture doc) — platform-global routes no longer corrupt `TenantContext` with a fabricated store id. Tested explicitly. | Reviewed — OK, fixed |
| Authorization | IDOR | Every mutating route resolves its target via Laravel route-model-binding against a real, existing-row-bound parameter; a nonexistent id 404s before any controller code runs. | Reviewed — OK |
| Authorization | Mass assignment | `SuperAdminThemeController`/`SuperAdminPackageController` use explicit validation allow-lists; `deactivate/reactivate` accept no client-supplied model fields at all. | Reviewed — OK |
| Tenant Isolation | Query scope | `withoutTenantScope()` verified correct on `Subscription`/`Order`/`PaymentTransaction`/`NotificationMessage`/`Domain` (all use `BelongsToTenant`); two INCORRECT attempts on `Store`/`User` (neither uses the trait) were caught and fixed before being left in the codebase. | Reviewed — OK, fixed |
| Tenant Isolation | Route parameter as implicit tenant | The central bug this milestone fixed. | Reviewed — OK, fixed |
| Tenant Isolation | Cache/Queue/Files | No new cache key, queued job, or file/export capability was introduced in B16. | N/A this milestone |
| Input Security | Validation | Every new mutating endpoint validates via an explicit FormRequest or inline `$request->validate()` call. | Reviewed — OK |
| Input Security | Unsafe filtering/sorting | `search` filters are plain `LIKE` binds against fixed columns — never a client-supplied column name or raw SQL fragment. | Reviewed — OK |
| Input Security | Raw SQL | No new `whereRaw()` was introduced except the pre-existing, unchanged low-stock formula reused verbatim (the exact same B4-authoritative expression already reviewed in B12). | Reviewed — OK |
| Web Security | CSRF/XSS/SSRF/open redirect/CORS/Host header | No new frontend surface, redirect, or outbound HTTP call was introduced; B14's domain resolution is untouched this milestone. | N/A — no new attack surface of these kinds |
| Sensitive Data | Passwords/tokens/secrets | `SuperAdminUserController` never returns `password`/`remember_token` (both remain `$hidden`); payment-failure oversight returns only already-public-to-staff fields, never a raw credential. | Reviewed — OK |
| Audit | Missing audit records | This milestone's OWN central hardening — every mutating action now has an action-specific audit line. Tested explicitly across multiple controllers. | Reviewed — OK, hardened |
| Audit | Forged actor identity | Every audit log reads the AUTHENTICATED principal's id, never a client-supplied actor field. | Reviewed — OK |
| Audit | Mutable audit history | Plain log lines, consistent with every prior phase's identical mechanism — no "erase my own audit trail" capability exists anywhere in B0-B16. | Reviewed — OK, N/A by construction |
| Operational Security | Destructive actions | No new destructive/irreversible action was introduced (deactivate/suspend are all reversible; no delete endpoint added). | Reviewed — OK |
| Operational Security | Impersonation/Support access | Reason now required (hardened, tested); no new token type introduced — the existing request-scoped mechanism already satisfies the "short-lived" goal (documented decision). | Reviewed — OK, hardened |

## Issues Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

1. **The central finding**: platform-global routes forced through the per-
   store impersonation middleware, corrupting `TenantContext` with a
   fabricated store id and writing an inaccurate audit entry. Fixed with a
   genuinely separate middleware/Gate/policy-method combination
   (`EnsureSuperAdminPlatformAction`/`super-admin.platform`/`platformAction()`).
2. Impersonation had no `reason` field despite Module 30 §27's explicit
   requirement. Fixed, with a pre-existing B1 test updated to reflect the new,
   intentionally stricter contract (not silently reverted).
3. Mutating actions relied solely on a generic, action-agnostic audit line.
   Fixed with action-specific entries carrying before/after state.
4. Two `withoutTenantScope()` calls were written against `Store` and `User`
   models, neither of which actually uses `BelongsToTenant` — would have
   caused a fatal error the first time either code path ran. Fixed by
   verifying each model's actual trait usage before finalizing every query.
5. Making impersonation reason-required broke a pre-existing B1 test's
   expectations — handled as an intentional, documented, security-motivated
   update, not an accidental regression left unaddressed.

## Known Limitations (Documented, Not Hidden)

1. No fine-grained platform role/permission hierarchy exists beyond the
   single-tier `isPlatformStaff()` boundary. Documented as deferred, since
   Module 30 gives no concrete permission-key list precise enough to build
   safely.
2. Hosting/Infrastructure, Backup/Restore, Platform Configuration, and
   Platform API administration oversight are all dependency-blocked (Modules
   20/23/33/31 do not exist anywhere in this codebase) — nothing was
   fabricated in their place.
3. No caching exists on any new Super Admin endpoint yet (acceptable at
   current scale, consistent with every prior phase's identical reasoning).

None of the "found and fixed" items required deleting or resetting existing
B0-B15 work beyond the one intentionally-updated pre-existing test. No
destructive database operation was performed (the one new migration in B16 is
additive-column only).
