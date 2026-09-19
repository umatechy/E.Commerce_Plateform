# Phase B2 — Focused Security Review

Static/design-level review only — **NOT EXECUTED — DEFERRED TO VS CODE RUNTIME
VERIFICATION** (no SAST/DAST tool run, no penetration test performed).

| Item | Finding | Status |
|---|---|---|
| **Tenant isolation** | `Subscription`, `UsageCounter`, `Role` all use `BelongsToTenant`; a cross-tenant subscription/usage row cannot resolve through `/api/v1/subscription[/usage]` because those endpoints never accept a store/subscription ID at all — they always resolve from `$request->user()->activeStoreId()`, which itself only ever resolves to a store the authenticated user actually belongs to (Phase B1's `StoreSwitcher`). | Reviewed — OK |
| **Subscription access** | `SubscriptionPolicy::view()` re-checks `$subscription->store_id === $user->activeStoreId()` as a second, independent layer beneath the tenant scope — defense-in-depth, same pattern as `RolePolicy` from B1. | Reviewed — OK |
| **Entitlement bypass** | Every entitlement/usage check resolves `store_id` exclusively from the server-side `TenantContext` (`EntitlementService`/`UsageTrackingService` constructors) — no method on either service accepts a caller-supplied store ID. A controller cannot pass a client-supplied store ID into these services even if it wanted to; the services simply have no parameter for it. | Reviewed — OK |
| **Usage-limit bypass** | Verified: `assertWithinLimit()`'s hard/soft branch, and the atomic increment in `UsageTrackingService`, are the only two places usage is checked/recorded. No controller in this milestone increments a counter directly via the model — all go through the service. | Reviewed — OK |
| **IDOR** | `SuperAdminPackageController::update(PackageRequest, Package $package)` — `Package` is platform-level (no tenant scope), so route-model binding alone does not protect it; **this is why every method explicitly calls `Gate::authorize('manage', Package::class)` before touching the model** — verified present in `index()`, `store()`, `update()`. | Reviewed — OK |
| **Privilege escalation** | `PackagePolicy`/`SubscriptionPolicy::manage()` both check `isPlatformStaff()` (the same structurally-separate `platform_role` column from B1) — no store-scoped Role/Permission can satisfy either check. | Reviewed — OK |
| **Super Admin boundary** | Package/Subscription admin routes added in B2 sit inside the SAME route group (`can:super-admin.impersonate` + `super_admin.impersonate` middleware) established in B1 — no second, weaker Super Admin gate was introduced. `SuperAdminSubscriptionController` methods take a `Store $store` route parameter but every method's actual authorization is the ROUTE GROUP's middleware, not the controller — verified this group wraps all of them. | Reviewed — OK |
| **Mass assignment** | `PackageRequest`/`ChangeStorePackageRequest` — explicit rule sets, no `store_id`/`package_id` accepted directly as a raw foreign key from the client where it matters (package change resolves the package by validated `package_code`, looked up server-side, not trusted as a raw ID). | Reviewed — OK |
| **API manipulation** | `SubscriptionController::show()`/`usage()` derive the store exclusively from the authenticated session — no route parameter exists for either endpoint that a client could manipulate. | Reviewed — OK |
| **Frontend-only checks** | `Pages/Billing/Overview.tsx` only displays data already authorized server-side; it contains no client-side entitlement/limit enforcement of any kind (it doesn't gate any action, only displays status) — nothing here to bypass. | Reviewed — OK, N/A (no enforcement exists client-side to bypass) |
| **Cache leakage** | Tenant-prefixed cache key unchanged from B0/B1's pattern; `SubscriptionLifecycleService` invalidates every key for a store on every transition — reviewed the invalidation call sites: present in `auditAndInvalidate()` (package change, trial start) AND `transitionStatus()` (status changes) — no transition path skips invalidation. | Reviewed — OK |
| **Event leakage** | No new outbox events introduced this milestone (see architecture doc "Events") — nothing to review yet for this item. | N/A this milestone |
| **Race conditions** | Addressed head-on — see `docs/architecture/b2-packages-entitlements.md` "Concurrency". The one operation that previously would have been check-then-write in application code (usage counting) is now a single atomic SQL statement. | Reviewed — OK, fixed |
| **Unauthorized package modification** | Covered by "Super Admin boundary" and "Privilege escalation" above. | Reviewed — OK |
| **Unauthorized subscription modification** | Same. Additionally: `changePackage()`/`suspend()`/`reactivate()` on `SubscriptionLifecycleService` are only ever called from Super-Admin-gated controllers or from `AuthController::register()` (trial start, which creates the FIRST subscription, not a modification of an existing one) — verified by inspection, no other call site exists. | Reviewed — OK |
| **Sensitive data exposure** | `PackageResource` intentionally has no pricing/billing field (Module 04's own separation of technical entitlements from commercial pricing, owned by Module 29) — nothing sensitive to leak yet. `SubscriptionResource`/`UsageOverviewResource` expose only this store's own status/usage, already access-controlled above. | Reviewed — OK |

## Issues Found and Fixed Within B2 Scope

1. **`EntitlementService::entitlementFor()` called a non-existent `Model::only()`
   method** (carried from B0/B1, never executed until now) — would have thrown a fatal
   error on the very first entitlement check in a real runtime. **Fixed.**
2. **`Store::activeSubscription()` relation was filtered to literal `status = 'active'`**,
   which would have silently returned `null` for Trialing/GracePeriod/PastDue stores —
   meaning a trialing store (the default state for every newly registered store) would
   have had ZERO entitlements resolve, blocking every feature for every new signup.
   **Fixed** by adding `Store::currentSubscription()` (status-agnostic) and switching
   `EntitlementService`/`SubscriptionLifecycleService` to use it.
3. **No default trial subscription was created at registration** (B1 gap, Module 04 §18
   violation) — **fixed** via `SubscriptionLifecycleService::startTrial()`, called in
   `AuthController::register()`'s existing transaction.
4. **`SubscriptionStatus` was missing 4 of Module 04 §17's 9 documented states** —
   **fixed**, additively (no existing case renamed/removed).

## Issues Documented for Later Modules (Outside B2 Scope)

1. Usage counter reconciliation (`UsageTrackingService::reconcile()`) has no concrete
   implementation yet — correctly deferred until a source-of-truth table exists
   (Phase B3+).
2. Subscription/package change events are not yet published to the outbox — deferred
   until a real consumer (Module 21 Notifications, Module 29 Billing) needs them, per
   the architecture doc's "Events" section.
3. Add-ons, promotions, and contract-override entitlement layers (Module 04 §23–27)
   are architecturally anticipated (the entitlement priority order is documented) but
   have no tables or code yet — correctly out of scope per "Do not invent unnecessary
   commercial fields" and the milestone's scope control instructions.

None of the "found and fixed" items required deleting or resetting existing B0/B1 work,
and no destructive database operation was performed anywhere in this milestone (all
migrations are additive; no `DROP`, no `TRUNCATE`, no data deletion).
