# Phase B2 — Packages, Subscriptions & Entitlements Architecture

## Package Model

Platform-level catalog (`Package` + `PackageEntitlement`), unchanged shape from B0/B1,
extended additively with `enforcement` (hard/soft, Module 04 §10), `period`
(persistent/monthly/daily/one_time/concurrent, §13), and `is_unlimited` (explicit
unlimited flag, distinct from "not yet configured" — see below).

## Entitlement Model

`EntitlementType::Feature` (boolean on/off) vs `EntitlementType::UsageLimit` (numeric),
per Module 04 §5 and §28's explicit "Feature Flags and Commercial Entitlements must
remain distinct" — a technically-shipped feature can still be commercially disabled for
a store, and this codebase enforces that distinction structurally: there is no code
path anywhere that lets a feature flag double as a commercial entitlement or vice versa.

## Why Absence Means Unlimited, Not Blocked, for Usage Limits

This is the single most important design decision in B2 and is stated explicitly
because it looks, at first glance, like a security gap:

- `EntitlementService::hasFeature()` — an **absent** feature-flag row means **not
  entitled** (deny-by-default). This IS a security-relevant default.
- `EntitlementService::limitFor()` — an **absent** usage-limit row means **unlimited**
  (no cap enforced). This is a **commercial** default, not a security one — usage limits
  govern resource consumption, not data access. Module 04 is explicit: *"Do not invent
  final package values unless the authoritative documents define them."* Since Business
  and Premium have no documented numeric limits for most metrics (only Basic's numbers
  recur as the spec's own worked examples), the only spec-compliant options were: (a)
  invent numbers (forbidden), or (b) treat "not yet configured" as unlimited until Umar
  Techy sets a real number via Super Admin. Option (b) was chosen — it is also the
  option least likely to accidentally break a paying Business/Premium customer's
  legitimate usage on launch day.

This is why `PackageSeeder` seeds numeric limits **only** for Basic.

## Usage Tracking (Module 04 §11–13)

`usage_counters` — one row per (store, metric, period boundary). Written exclusively
through `UsageTrackingService`, which uses a single atomic
`INSERT ... ON DUPLICATE KEY UPDATE count = count + ?` statement — this is the
concurrency-safety mechanism (see "Concurrency" below), not application-level locking.

Reconciliation (§12 — detecting counter drift) is a documented extension point
(`UsageTrackingService::reconcile()`) with no concrete implementation yet, because there
is no source-of-truth table (products, orders) to recount against until Phase B3/B5.

## Concurrency

The exact race this milestone's prompt describes — two simultaneous requests both
observing `usage < limit` and both proceeding — is closed by the atomic SQL statement
above: MySQL serializes concurrent `INSERT ... ON DUPLICATE KEY UPDATE` statements
against the same unique-key row, so the "check, then act" gap that causes the race
simply does not exist at the database layer. `EntitlementService::assertWithinLimit()`
still checks-then-throws in PHP, but the *increment itself* — the operation that could
otherwise race — never goes through a read-modify-write in application code.

## Subscription Lifecycle (Module 04 §17, §21–22, §36–38)

`SubscriptionStatus` — the 9 states Module 04 §17 itself suggests (`Pending, Trialing,
Active, PastDue, GracePeriod, Suspended, Cancelled, Expired, Archived`), used verbatim.
`SubscriptionStatus::grantsAccess()` is the documented (not spec-mandated verbatim, since
§29's exact allow-list isn't given) decision for which states grant feature access:
Trial, Active, GracePeriod, and PastDue all grant access (PastDue as a warning state,
matching §36's "avoid immediate destructive behavior"); Pending, Suspended, Cancelled,
Expired, and Archived deny it.

`SubscriptionLifecycleService` is the ONLY code path that changes a subscription's
package or status. Every transition: runs inside a `DB::transaction()`, invalidates that
store's cached entitlements (never serves stale data after a change), writes an audit
log entry (`Log::channel('audit')`, the same pattern established for Super Admin
impersonation in Phase B1), and never touches any table outside
subscriptions/stores/audit/outbox — verified by inspection, directly implementing
Module 04 §20's "only entitlement and resource access changes."

## Upgrade / Downgrade

One method, `changePackage()`, for both directions — no `if upgrading` / `if
downgrading` branching, per Module 04 §4's "Package Design Principle." On downgrade, any
usage that now exceeds the new package's limits is **detected and reported** (returned
as an `over_limit` array to the caller/API response) — **never auto-deleted or
auto-archived**, per §22's explicit instruction that "the final policy will be defined
later" and that deletion is only one of several possible future resolution options.

## Store → Subscription Relationship

`Store::currentSubscription()` (new in B2, replacing use of the mis-scoped
`activeSubscription()` relation for anything access-related — see
`docs/development/b2-inspection-findings.md`) resolves the store's subscription
regardless of status, since `SubscriptionStatus::grantsAccess()` — not the literal string
`'active'` — is what actually determines access. `activeSubscription()` is retained only
for callers that explicitly want a literal `status = 'active'` row (e.g. a future billing
report intentionally excluding trials).

## Default Package / Initial Store

Module 04 does not specify an exact default trial package, so this is a documented
implementation decision (`config/packages.php`, `DEFAULT_TRIAL_PACKAGE_CODE`, defaulting
to `basic`) rather than a silently invented one — configurable without a code change, per
§14's explicit requirement. `AuthController::register()` now calls
`SubscriptionLifecycleService::startTrial()` in the same transaction as Store creation
(B1 left this out entirely — see inspection findings item B), closing Module 04 §18's
"every Store/Tenant must have a current package entitlement" requirement.

## Package Administration / Subscription Administration

`PackagePolicy`/`SubscriptionPolicy` — Super-Admin-only for every mutation, consistent
with Module 04's explicit statement that normal tenant users must not modify platform
packages. `SuperAdminPackageController`/`SuperAdminSubscriptionController` sit behind the
SAME doubly-guarded route group (`can:super-admin.impersonate` +
`super_admin.impersonate` middleware) established in Phase B1 — no new Super Admin gate
mechanism was invented.

## API Endpoints Added in B2

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/api/v1/public/packages` | none | marketing/pre-signup catalog (Module 04 §40) |
| GET | `/api/v1/subscription` | Sanctum | the authenticated user's own store only |
| GET | `/api/v1/subscription/usage` | Sanctum | usage overview for own store only |
| GET/POST/PUT | `/api/v1/super-admin/packages[/{package}]` | Sanctum + Gate + middleware | platform package admin |
| POST | `/api/v1/super-admin/stores/{store}/subscription/change-package` | Sanctum + Gate + middleware | |
| POST | `/api/v1/super-admin/stores/{store}/subscription/suspend` | Sanctum + Gate + middleware | |
| POST | `/api/v1/super-admin/stores/{store}/subscription/reactivate` | Sanctum + Gate + middleware | |

## Events

No new outbox event types were wired into `RecordsOutboxEvents` in B2 — every
subscription/package change currently goes through the synchronous
`Log::channel('audit')` pattern only, matching Phase B1's precedent. The prompt lists
subscription/package events as "potential" and instructs "only implement events required
by the specification/current architecture" — since no consumer exists yet that needs
these as asynchronous integration events (no webhook, no notification module built yet),
adding outbox events now would be speculative infrastructure with nothing to consume it.
This is a documented, deliberate scope decision, not an oversight — flagged for revisit
when Module 21 (Notifications) or Module 29 (Billing) need to react to these
transitions asynchronously.

## Cache

`EntitlementService::entitlementFor()` — tenant-prefixed cache key
(`tenant:{store_id}:entitlement:{key}`, unchanged pattern from B0/B1), 5-minute TTL
(`config('packages.entitlement_cache_ttl_minutes')`). `SubscriptionLifecycleService`
invalidates every known entitlement key for a store on any package/status change — see
`invalidateAllEntitlementCacheKeys()`'s docblock for why this is exhaustive
key-by-key forgetting rather than a (Laravel-generic-cache-incompatible) wildcard.

## Frontend

`Pages/Billing/Overview.tsx` — read-only current package + usage display. No
upgrade/downgrade action UI and no payment/checkout UI (Module 29's scope, explicitly out
of B2 per this milestone's instruction).
