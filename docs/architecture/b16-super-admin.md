# Phase B16 — Umar Techy Super Admin Architecture (Module 30)

See `docs/development/b16-inspection-findings.md` for the full inspection
report, the Module 30 A-V scope classification table, and five distinct
findings (four bugs, one intentional/justified test update) found and fixed
this milestone. This was an INSPECT-AND-HARDEN milestone, not greenfield —
the existing Phase B1/B7/B8/B9 Super Admin foundation (impersonation,
package/subscription administration, domain suspension) was preserved and
extended, never rebuilt.

## The Central Fix: Platform-Global vs Per-Store Super Admin Actions

The single most important finding: `EnsureSuperAdminImpersonation` — correct
for every `{store}`-scoped route — was ALSO applied to `/super-admin/packages`
routes that have no target store at all, causing `(int) $request->route('store')`
to evaluate to `0` and corrupt `TenantContext` via
`markImpersonation($user->id, 0)`. B16 introduces a genuinely separate
concept: `EnsureSuperAdminPlatformAction` (new middleware) +
`super-admin.platform` (new Gate ability) + `SuperAdminAccessPolicy::platformAction()`
(new policy method) for routes with NO target store — it calls
`TenantContext::resolveToPlatform()` (the correct, already-existing method)
and logs an accurate `super_admin.platform_action` audit entry, never a
fabricated store id. `EnsureSuperAdminImpersonation` itself is completely
unchanged (confirmed by `git diff` showing zero modifications) — every
existing `{store}`-scoped route continues working exactly as before.

## Route Groups, Now Explicitly Two

| Group | Middleware | Used for |
|---|---|---|
| Platform-global | `can:super-admin.platform` + `super_admin.platform` | Package/Theme catalog CRUD, platform dashboard, store list/search, user oversight, payment/notification/domain failure oversight |
| Per-store impersonation | `can:super-admin.impersonate` + `super_admin.impersonate` | Impersonate, store detail, subscription change/suspend/reactivate, domain suspend/reactivate |

## Audit Hardening — Two Layers, Neither Replacing the Other

Every Super Admin route already got a GENERIC audit line from its middleware
(`super_admin.impersonation.started` or the new `super_admin.platform_action`)
proving WHO accessed the surface and WHEN. B16 adds ACTION-SPECIFIC audit
entries (`super_admin.subscription.package_changed`,
`super_admin.subscription.suspended/reactivated`, `super_admin.package.created/updated`,
`super_admin.theme.created/updated`, `super_admin.user.deactivated/reactivated`,
`super_admin.store.impersonated`) carrying before/after state and reason where
applicable — proving WHAT was done. Both layers serve a genuinely different
audit purpose and are kept.

## Impersonation Hardening — Reason Now Required

`SuperAdminStoreController::impersonate()` now requires an explicit `reason`
query parameter (Module 30 §27's own explicit requirement), included in the
new action-specific audit log. This intentionally changed the pre-existing
Phase B1 `SuperAdminCrossTenantAccessTest`'s expected behavior — the test was
updated accordingly (new reason param, both expected log calls, plus a new
regression test locking in the 422-without-reason behavior), documented as a
deliberate, security-motivated API change, never silently reverted or ignored.

## New Platform-Wide Dashboard — a Separate Service by Design

B12's `DashboardService` is intentionally tenant-scoped. `SuperAdminDashboardService`
is a new, small, separate service that explicitly bypasses tenant scoping
(`withoutTenantScope()`) on the models that actually use `BelongsToTenant`
(`Subscription`, `Order`, `PaymentTransaction`) to aggregate across every
store — reusing B12's own Metric Dictionary definitions (Revenue =
`grand_total_minor`, Collected Amount = `payment_transactions`) rather than
inventing new ones. Two incorrect `withoutTenantScope()` calls on `Store` and
`User` (neither of which actually uses `BelongsToTenant`) were caught and
fixed before being left in the codebase — see inspection findings "Fourth
Finding."

## New Platform-Wide User Account Lock

`users.is_active` (new, additive column, default `true`) — a genuinely new
capability Module 30 §12 required but that did not exist anywhere in B0-B15.
Checked in `LoginRequest::authenticate()` AFTER a successful credential match
(never reveals whether credentials were correct if the account happens to be
locked — same generic failure message either way), with the session torn
down immediately if inactive. Structurally separate from the per-store
`store_user.status` pivot column (Phase B1, untouched) and from the Customer
guard entirely (confirmed by a dedicated regression test that a Customer's
own login remains fully functional after an unrelated User is deactivated).

## Theme Catalog Management — Mirrors Package Exactly

`SuperAdminThemeController` (new) manages the `Theme` platform catalog
(Phase B15) the same way `SuperAdminPackageController` already manages
`Package` — same authorization shape, same audit pattern, sits in the new
platform-global route group.

## Read-Only Oversight Endpoints

`SuperAdminPaymentController::failures()`, `SuperAdminNotificationController::failures()`,
`SuperAdminDomainController::indexAll()` — all read-only, cross-store, reusing
each domain's own authoritative models unchanged (B7 `PaymentTransaction`, B11
`NotificationMessage`, B14 `Domain`). None mutates any state; none duplicates
delivery/payment/verification logic.

## What Was Deliberately Not Built (Dependency-Blocked, Not Fabricated)

Hosting/Infrastructure oversight (Module 20 absent), Backup/Restore (Module 23
absent — this milestone's own explicit "do not implement fake restore
operations" is honored by building nothing here), Platform Configuration
(Module 33 absent), Platform API administration (Module 31 absent),
fine-grained platform role/permission hierarchy beyond the existing single-
tier `isPlatformStaff()` boundary, a new impersonation-scoped token type (the
existing request-scoped `markImpersonation()` mechanism already satisfies the
underlying "short-lived" security goal). Full rationale for each in the
inspection findings.

## API Endpoints Added/Reorganized in B16

| Method | Path | Group |
|---|---|---|
| GET/POST | `/api/v1/super-admin/packages[/{id}]` | platform-global (moved from impersonation group) |
| GET/POST/PUT | `/api/v1/super-admin/themes[/{id}]` | platform-global |
| GET | `/api/v1/super-admin/dashboard` | platform-global |
| GET | `/api/v1/super-admin/stores` (search/list) | platform-global |
| GET | `/api/v1/super-admin/users[/{id}]` | platform-global |
| POST | `/api/v1/super-admin/users/{id}/deactivate\|reactivate` | platform-global |
| GET | `/api/v1/super-admin/payments/failures` | platform-global |
| GET | `/api/v1/super-admin/notifications/failures` | platform-global |
| GET | `/api/v1/super-admin/domains` (all stores) | platform-global |
| GET | `/api/v1/super-admin/stores/{store}` (detail) | impersonation |
| GET | `/api/v1/super-admin/stores/{store}/impersonate?reason=...` | impersonation |
| POST | `/api/v1/super-admin/stores/{store}/subscription/...` | impersonation (unchanged) |
| POST | `/api/v1/super-admin/stores/{store}/domains/{id}/suspend\|reactivate` | impersonation (unchanged) |

## UI

Not built in B16, matching every backend-focused phase's own precedent.
