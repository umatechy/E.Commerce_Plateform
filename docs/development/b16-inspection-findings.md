# Phase B16 — Step 1: Inspection + Scope Decision (Super Admin: Module 30)

## Inspection of Existing Implementation

Confirmed existing (Phase B1, extended by B7/B8/B9): `EnsureSuperAdminImpersonation`
middleware, `SuperAdminAccessPolicy` (`impersonate()`), `PackagePolicy`
(`manage()`), `SuperAdminStoreController` (impersonate), `SuperAdminDomainController`
(suspend/reactivate, Phase B14), `SuperAdminSubscriptionController`
(change-package/suspend/reactivate), `SuperAdminPackageController` (index/store/
update). All four controllers sit under one route group:
`Route::middleware(['can:super-admin.impersonate', 'super_admin.impersonate'])->prefix('super-admin')`.

## Critical Bug Found During Inspection — Platform-Global Routes Forced Through the Per-Store Impersonation Middleware

`EnsureSuperAdminImpersonation::handle()` reads `(int) $request->route('store')`
and calls `TenantContext::markImpersonation($user->id, $targetStoreId)`
unconditionally. **`GET /super-admin/packages` and `POST /super-admin/packages`
have no `{store}` route parameter at all** (`Package` is a platform-global
catalog, not tenant-owned) — for these two routes, `$request->route('store')`
is `null`, so `(int) null` evaluates to `0`, and the middleware calls
`markImpersonation($user->id, 0)`, corrupting `TenantContext` into believing it
is impersonating a non-existent "Store 0" for the remainder of that request,
and writing a misleading audit entry (`target_store_id: 0`) for what is
actually a platform-global action, not tenant impersonation at all. This
directly violates this milestone's own Non-Negotiable ("a route parameter...
must NOT become an implicit tenant context") and its audit-accuracy
requirement (§26: target information must be correct). `Package` itself is
not tenant-scoped (no `BelongsToTenant`), so this bug has not corrupted any
actual Package data — but it is a latent risk (any OTHER tenant-scoped model
touched later in the same request would silently receive `store_id = 0`) and
an active audit-accuracy defect today.

**Fix applied**: a new, separate `EnsureSuperAdminPlatformAction` middleware
for genuinely platform-global routes (no target store at all) — it checks
`isPlatformStaff()`, calls `TenantContext::resolveToPlatform()` (the correct,
already-existing method for this, never `markImpersonation()`), and logs an
accurate `super_admin.platform_action` audit entry naming the real action,
never a fabricated store id. `/super-admin/packages` and the new
`/super-admin/themes` routes (below) use this middleware;
`EnsureSuperAdminImpersonation` is left completely unchanged for every
`{store}`-scoped route, which continues working correctly.

## Second Finding — Impersonation Had No Reason Field

Module 30 §27 requires impersonation to carry an explicit reason. The existing
`SuperAdminStoreController::impersonate()` accepted none. **Fix applied**:
`impersonate()` now requires a `reason` query parameter (validated,
non-empty), included in the existing audit log line — an additive change to
one controller method's signature, not a rewrite of the impersonation
mechanism itself.

## Third Finding — Mutating Super Admin Actions Had Only Generic, Not Action-Specific, Audit Entries

`SuperAdminSubscriptionController::changePackage/suspend/reactivate` and
`SuperAdminPackageController::store/update` relied ENTIRELY on
`EnsureSuperAdminImpersonation`'s one generic
`super_admin.impersonation.started` log line (route path + target store only)
— with no record of WHAT changed (old package code → new, suspension reason,
which package fields were edited). Module 30 §26 wants "before state, after
state, reason" for privileged mutations specifically. **Fix applied**: each
mutating method now writes its own specific `Log::channel('audit')` entry
(e.g. `super_admin.subscription.package_changed` with
`from_package_code`/`to_package_code`) in addition to (never replacing) the
existing generic middleware-level log — both layers serve a purpose (the
middleware log proves WHO accessed the Super Admin surface and WHEN; the
action-specific log proves WHAT they did).

## Scope Classification (Module 30's Own Requested A-V Checklist)

| Area | Classification | Notes |
|---|---|---|
| A. Platform Dashboard | REQUIRED NOW | New `SuperAdminDashboardService`/Controller — platform-wide, read-only, reuses existing tables |
| B. Store/Tenant Management | PARTIALLY IMPLEMENTED → HARDENED + EXTENDED | Impersonate existed; added list/search/detail |
| C. User/Staff Oversight | REQUIRED NOW | New — search/inspect/deactivate platform users, read-only role/membership inspection |
| D. Packages & Subscription Oversight | ALREADY IMPLEMENTED → HARDENED | Routing bug fixed, audit hardened |
| E. Entitlement Configuration | ALREADY IMPLEMENTED | Covered by Package CRUD (entitlements are sub-resources of Package, B2) |
| F. Domain Oversight | ALREADY IMPLEMENTED → EXTENDED | Suspend/reactivate existed (B14); added list-all for oversight |
| G. Hosting/Infrastructure Oversight | DEPENDENCY BLOCKED | Module 20 does not exist anywhere in this codebase — not fabricated |
| H. Payment/Gateway Oversight | REQUIRED NOW (read-only) | New — failed payments/webhooks across stores, reusing B7 models unchanged |
| I. Order/Commerce Visibility | DEFERRED | Adequately served by each store's own B12 dashboard; a platform-wide order browser is a nice-to-have, not load-bearing for this milestone's own stated priorities (dashboard, tenant mgmt, audit) |
| J. Inventory/Store Health | REQUIRED NOW (minimal) | Folded into store detail view (low-stock count) — no separate Module 24 domain invented |
| K. Notifications/Communications Oversight | REQUIRED NOW (read-only) | New — delivery-failure counts, reusing B11 models unchanged |
| L. Marketing/Promotions Oversight | DEFERRED | No concrete platform-level requirement beyond what B12's own per-store analytics already exposes |
| M. Theme Management | REQUIRED NOW | New `SuperAdminThemeController` — Theme catalog CRUD, mirrors `SuperAdminPackageController` exactly |
| N. SEO/Content Operational Oversight | DEFERRED | No concrete platform-level moderation requirement given |
| O. Reports/Analytics | Covered by A | The Platform Dashboard is this milestone's analytics deliverable |
| P. Backup/Restore | DEPENDENCY BLOCKED | Module 23 does not exist — never fabricated; explicitly NOT implemented per this milestone's own "do not implement fake restore operations" |
| Q. Store Health/Resource Monitoring | REQUIRED NOW (minimal, folded into J) | No dedicated Module 24 domain exists to integrate with; a full resource-monitoring system is out of scope |
| R. Platform Configuration | DEPENDENCY BLOCKED | Module 33 does not exist — not fabricated |
| S. Audit & Security Operations | REQUIRED NOW | The three fixes above ARE this milestone's core audit-hardening work |
| T. Store Suspension/Activation | ALREADY IMPLEMENTED | Subscription suspend/reactivate (B2/existing) IS the store-suspension mechanism — Module 30 §10's own warning against treating platform suspension and subscription cancellation as "accidental equivalents" is honored by NOT inventing a third, competing suspension concept; this platform has exactly one (subscription-level) and one domain-level (B14) suspension mechanism, kept deliberately separate from each other, and neither is duplicated here |
| U. Support/Operational Access (Impersonation) | ALREADY IMPLEMENTED → HARDENED | Reason field added (see Second Finding) |
| V. Platform-level API administration | DEPENDENCY BLOCKED | Module 31 (Developer API Platform) does not exist |

## Architectural Decision — Platform Dashboard Is a New, Separate Service, Not a Reuse of B12's DashboardService

B12's `DashboardService` is intentionally, structurally tenant-scoped (every
query implicitly filtered by the resolved `TenantContext`). A platform-wide
view needs the OPPOSITE — explicit `withoutTenantScope()` aggregation across
ALL stores. Rather than adding a "platform mode" branch into B12's own
tenant-scoped service (which would blur that service's single responsibility
and risk a tenant-isolation regression in its far more heavily-used tenant
path), B16 adds a small, separate `SuperAdminDashboardService` that reuses the
SAME underlying tables (orders, subscriptions, payment_transactions) via
explicit cross-tenant queries, following the same Metric Dictionary
definitions B12 already established (Revenue = grand_total_minor, Collected
Amount = payment_transactions, etc.) rather than inventing new ones.

## Architectural Decision — No New "Platform Role" Hierarchy

`User.platform_role` (a plain nullable string, Phase B1) already distinguishes
platform staff from ordinary users; every existing Policy checks only
`isPlatformStaff()` (any non-null value). Module 30 §13 raises the possibility
of a finer-grained platform role/permission hierarchy (e.g. "billing admin"
vs "support agent" with different capabilities), but no concrete list of
platform-level permission keys or their exact boundaries is given precisely
enough to build safely without inventing one. **Decision**: B16 preserves the
existing single-tier `isPlatformStaff()` boundary for every capability this
phase adds — every Super Admin action requires ONLY "is platform staff," no
finer distinction — and documents fine-grained platform-role separation as
explicitly deferred, not silently skipped.

## Architectural Decision — No Impersonation Session/Token Change

Module 30 §27 describes a "short-lived session/token" for impersonation.
Inspection confirms the EXISTING mechanism does not issue a separate
impersonation-scoped token at all — `TenantContext::markImpersonation()` only
affects the CURRENT request's tenant resolution (it is request-scoped, not
persisted to a session), meaning every subsequent impersonation-boundary
request must independently pass through `EnsureSuperAdminImpersonation` again
with the Super Admin's OWN Sanctum token. This is, in effect, already
"short-lived" (exactly one request), which satisfies the underlying security
goal (no persistent, silently-renewing impersonation session) without needing
a new token type. Documented as the existing, sufficient design — not
rebuilt.

## Deferred (Explicitly Named, Not Silently Dropped)

Hosting/Infrastructure oversight (G, Module 20 absent), Backup/Restore (P,
Module 23 absent), Platform Configuration (R, Module 33 absent), Platform API
administration (V, Module 31 absent), fine-grained platform role/permission
hierarchy (§13), a genuinely new impersonation token mechanism (§27), Order/
Commerce platform-wide browsing (I), Marketing/Promotions platform oversight
(L), SEO/Content platform moderation (N) — each named so a future phase's own
Step 1 inspection finds this documented list, exactly matching this project's
established pattern since B8.

## Fourth Finding — Two Incorrect withoutTenantScope() Calls on Non-Tenant-Scoped Models

While writing `SuperAdminDashboardService` and `SuperAdminUserController`, early
drafts called `Store::query()->withoutTenantScope()` and
`User::query()->withoutTenantScope()`. Neither `Store` (which IS the tenant
itself, not tenant-scoped to something else) nor `User` (intentionally NOT
tenant-scoped via `BelongsToTenant` — a staff user can belong to multiple
stores through the `store_user` pivot) actually use the `BelongsToTenant`
trait, so `withoutTenantScope()` is not a valid method on either model and
would have caused a fatal error the first time either code path ran. Caught
before being left in the codebase — fixed by calling `Store::query()`/
`User::query()` directly (no scope to bypass on either model). Every OTHER
`withoutTenantScope()` call added this milestone (`Subscription`, `Order`,
`PaymentTransaction`, `NotificationMessage` — all of which DO use
`BelongsToTenant`) was verified correct by checking each model's trait usage
before writing the query.

## Fifth Finding — Making Impersonation Reason-Required Broke a Pre-Existing B1 Test (Intentional, Justified, Updated Honestly)

Adding the required `reason` parameter (Second Finding fix) and the new
action-specific audit log (Third Finding fix) to
`SuperAdminStoreController::impersonate()` broke Phase B1's own
`SuperAdminCrossTenantAccessTest::test_platform_staff_impersonation_is_audit_logged()`
— it called the endpoint with no `reason` at all (now a 422) and only expected
ONE `Log::info()` call (now two: the pre-existing generic middleware entry
plus the new action-specific one). This is NOT an accidental regression — it
is the direct, intended consequence of a deliberate, Module-30-mandated
security hardening to a genuinely security-critical endpoint. Per this
milestone's own instruction ("if an existing issue blocks... fix the minimum
required scope... add regression coverage... document the fix"), the test was
updated (not silently left broken, not reverted to avoid touching it): it now
supplies a `reason`, expects both audit log entries, and a NEW test method
(`test_impersonation_without_a_reason_is_rejected`) explicitly locks in the
422-without-reason behavior as a permanent regression guard.
