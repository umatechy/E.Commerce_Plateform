# Phase B2 — Step 1: Inspection of Existing Package/Subscription/Entitlement Code

## A. Correct and Reusable

- `Package`, `PackageEntitlement`, `EntitlementType` (feature/usage_limit distinction —
  correctly matches Module 04 §28 "Feature Flags vs Entitlements" at the type level).
- `Subscription` model shape (store_id, package_id, status, trial_ends_at,
  grace_period_ends_at, current_period_ends_at) — correct base fields, reused as-is.
- `EntitlementService::hasFeature()` — correct "absent entitlement = not entitled"
  (deny-by-default) semantics for feature flags.
- Migrations for `packages`, `package_entitlements`, `subscriptions` — correct base
  shape, extended additively (no columns removed or renamed).

## B. Incomplete (extended, not rewritten)

- **`SubscriptionStatus` enum only had 5 of the 9 states Module 04 §17 explicitly
  lists** (`Trialing, Active, GracePeriod, Suspended, Cancelled` — missing `Pending`,
  `PastDue`, `Expired`, `Archived`). Fixed by adding the 4 missing cases; no existing
  case renamed or removed (backward compatible with any B1 data).
- `EntitlementService` had no concept of: hard vs soft limits (Module 04 §10), usage
  periods (§13), actual usage tracking (§11–12), subscription-state-aware access
  (§29), or unlimited-vs-not-configured distinction. Extended with new methods; the
  two existing methods (`hasFeature`, `limitFor`) keep their original signatures and
  behavior for backward compatibility with any B1 caller.
- No default package/subscription was assigned when `AuthController::register()`
  created a new Store in B1 — a store existed with no `Subscription` row at all. This
  is a genuine functional gap for B2 to close (Module 04 §18: "Every Store/Tenant must
  have a current package entitlement").

## C. Incorrect (found and fixed this milestone)

- **`EntitlementService::entitlementFor()` called `Model::only([...])`, a method that
  does not exist on Eloquent's base `Model` class** (`only()` is a `Collection` method,
  not a `Model` method) — this would have thrown `Error: Call to undefined method` the
  first time any entitlement lookup actually ran against a real PHP runtime. This bug
  existed in the original B0/B1 code and was never caught because nothing has been
  executed in this environment yet (exactly the risk "do not assume every generated
  file is correct merely because it exists" warns about). **Fixed** by converting the
  model to an array first, then using `Illuminate\Support\Arr::only()`.

## D. Duplicated

None found.

## E. Missing (implemented in this milestone)

- Hard/soft limit distinction, usage period, and explicit unlimited flag on
  `package_entitlements` (additive migration).
- `usage_counters` table + `UsageCounter` model + `UsageTrackingService`
  (atomic, race-condition-safe increment/decrement — Module 04 §11–12).
- `SubscriptionLifecycleService` (trial start, upgrade/downgrade, suspend, cancel,
  expire, reactivate — Module 04 §17, §21–22, §36–38).
- `PackagePolicy`, `SubscriptionPolicy`.
- `PackageController` (public package listing for marketing/upgrade UI),
  `SubscriptionController` (store's own subscription + usage overview),
  `SuperAdminPackageController`, `SuperAdminSubscriptionController`.
- `PackageSeeder` (Basic/Business/Premium platform catalog).
- Frontend: `Pages/Billing/Overview.tsx`.
- Full B2 test suite.

## F. Security-Sensitive Findings

1. **No default subscription meant a newly registered store had undefined
   entitlement state** — `EntitlementService::entitlementFor()` would have returned
   `null` for every key (no `activeSubscription`), which fails safe (feature checks
   deny, usage checks are unlimited-by-default per the documented semantics below) —
   not a leak, but a functional gap that must be closed so every store has a real,
   intentional package from the moment it exists (Module 04 §18 is explicit that this
   is required, not optional).
2. **Usage-limit "unlimited by default when unconfigured" semantic must be
   documented, not just implicit** — `limitFor()` returning `null` for a
   never-configured key already meant "no cap enforced" in the B0/B1 code. This is
   deliberately preserved (see `docs/architecture/b2-packages-entitlements.md`
   "Why absence means unlimited, not blocked, for usage limits") rather than changed
   to deny-by-default, because Module 04 explicitly instructs: "Do not invent final
   package values unless the authoritative documents define them" — inventing a
   numeric cap for Business/Premium would violate that instruction, and silently
   blocking access instead would break every tier above Basic the moment a
   not-yet-configured limit key is checked. This is a documented, reviewed decision,
   not an oversight.
3. **Race condition risk in usage counting** — flagged explicitly by this milestone's
   "Concurrency" section. `UsageTrackingService` uses a single atomic
   `INSERT ... ON DUPLICATE KEY UPDATE count = count + 1` statement (MySQL-level
   atomicity via the table's unique constraint), not an application-level
   read-then-write check, closing the two-simultaneous-requests race described in the
   prompt's example.
