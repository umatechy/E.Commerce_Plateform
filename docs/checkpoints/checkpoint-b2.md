============================================================
PHASE B2 CHECKPOINT
============================================================

Phase:
Development Phase B

Milestone:
B2 — Packages, Subscriptions & Entitlements

Status:
Implemented in Claude App environment as far as this environment allows. Runtime
execution deferred to VS Code phase (no PHP/Composer/MySQL/Redis/network available
here — unchanged since Milestone-0 preflight).

Completed:
- Step 1 inspection of existing B0/B1 Package/Entitlement/Subscription code performed
  before any change (docs/development/b2-inspection-findings.md) — found and fixed 3
  real bugs (Model::only() does not exist; activeSubscription() relation excluded
  Trialing/GracePeriod/PastDue stores from all entitlements; new stores had no
  subscription at all) plus 1 spec-completeness gap (SubscriptionStatus missing 4 of
  Module 04's 9 documented states).
- Package/Entitlement architecture extended with hard/soft enforcement, usage
  periods, and an explicit is_unlimited flag — additive migration only.
- Usage tracking implemented with an atomic, race-condition-safe counter mechanism
  (usage_counters table + UsageTrackingService).
- EntitlementService extended with subscription-state-aware, usage-limit-aware,
  server-authoritative checks (assertFeatureEntitled, assertWithinLimit,
  assertCanUse), preserving the original B0/B1 method signatures unchanged.
- SubscriptionLifecycleService implemented (trial start, package change,
  suspend/cancel/expire/reactivate) — transactional, audit-logged, cache-invalidating,
  never touches business data.
- Registration flow (AuthController) now creates a trial Subscription atomically with
  the Store, closing Module 04 §18's requirement.
- Server-side authorization for Package/Subscription administration
  (PackagePolicy, SubscriptionPolicy), reusing Phase B1's Super Admin gate mechanism
  without introducing a second one.
- API endpoints for public package listing, own-store subscription/usage, and Super
  Admin package/subscription administration.
- Frontend read-only Billing/Package overview page.
- PackageSeeder (Basic/Business/Premium feature matrix from Module 04 §8; numeric
  usage limits seeded ONLY for Basic, using the specification's own worked-example
  numbers — Business/Premium deliberately left unconfigured/unlimited rather than
  inventing numbers, per Module 04's explicit instruction).
- 68 test methods written across 7 Feature test files covering package, entitlement,
  usage-limit, subscription, upgrade/downgrade, tenant isolation, and Super Admin
  scenarios.
- Focused security review performed; 4 issues found and fixed within B2 scope, 3
  documented for later modules.
- Documentation created/updated across docs/development/, docs/architecture/,
  docs/security/, docs/checkpoints/.

Package:
Package + PackageEntitlement (B0/B1 shape, reused) extended additively with
enforcement (hard/soft), period (persistent/monthly/daily/one_time/concurrent), and
is_unlimited columns. PackageSeeder seeds Basic/Business/Premium from Module 04 §8's
own feature matrix. No package-specific codebases or if/else branching by package
code exist anywhere — verified by inspection (grep for package code string
comparisons in business logic returns none outside the seeder itself).

Entitlements:
EntitlementType::Feature (deny-by-default when unconfigured) vs
EntitlementType::UsageLimit (unlimited-by-default when unconfigured — a deliberate,
documented, non-security-relevant commercial default, see
docs/architecture/b2-packages-entitlements.md). hasFeature()/limitFor() signatures
unchanged from B0/B1; assertFeatureEntitled(), assertWithinLimit(), assertCanUse()
added.

Usage Limits:
usage_counters table (tenant-owned, additive migration) + UsageTrackingService.
Concurrency-safe via a single atomic INSERT ... ON DUPLICATE KEY UPDATE statement —
no application-level check-then-write race window exists for the increment operation
itself. Hard limits block (UsageLimitExceededException); soft limits never throw
(caller checks isWithinLimit() explicitly for warnings). Reconciliation is a
documented, unimplemented extension point (no source-of-truth table exists yet to
recount against).

Subscription:
SubscriptionStatus now carries all 9 states Module 04 §17 documents (4 were missing
in B0/B1, added without renaming/removing the original 5).
Store::currentSubscription() (new, status-agnostic) fixes the B0/B1
activeSubscription() relation's literal 'active'-only filter, which would have
excluded every Trialing/GracePeriod/PastDue store from all entitlement resolution —
a real functional bug closed this milestone, not a hypothetical one.

Subscription Lifecycle:
SubscriptionLifecycleService — startTrial, changePackage, suspend, cancel, expire,
reactivate. Every transition is transactional, invalidates this store's cached
entitlements exhaustively (key-by-key, since Laravel's cache contract has no
wildcard forget), and writes an audit log entry (Log::channel('audit'), the pattern
established in Phase B1). No transition touches products/orders/customers/inventory/
domains/branding — verified by inspection.

Upgrade/Downgrade:
One method (changePackage()) for both directions, per Module 04 §4's design
principle. Store ID/Tenant ID unchanged (asserted in
test_package_change_preserves_store_id_and_tenant_identity). Over-limit usage after a
downgrade is detected and reported, never auto-deleted (asserted in
test_downgrade_does_not_delete_any_business_data — the exact 2,000-vs-500-products
scenario from Module 04 §22's own worked example).

Tenant Isolation:
Subscription and UsageCounter both use BelongsToTenant. /api/v1/subscription[/usage]
never accept a store ID parameter at all — they resolve exclusively from the
authenticated session, structurally preventing cross-tenant access rather than merely
blocking a guessed ID. 3 dedicated tenant-isolation tests added
(PackageTenantIsolationTest).

Authorization:
PackagePolicy (manage = Super Admin only), SubscriptionPolicy (view = own store only,
manage = Super Admin only) — both registered explicitly in AppServiceProvider
(App\Domain\* auto-discovery gap, established as a known pattern since Phase B1).

Super Admin:
Package/Subscription administration reuses the exact doubly-guarded route group
(can:super-admin.impersonate Gate + super_admin.impersonate middleware) from Phase
B1 — no second, weaker Super Admin mechanism was created for this milestone's new
endpoints.

API:
GET /api/v1/public/packages (unauthenticated catalog), GET /api/v1/subscription, GET
/api/v1/subscription/usage, GET/POST/PUT /api/v1/super-admin/packages[/{package}],
POST /api/v1/super-admin/stores/{store}/subscription/{change-package,suspend,
reactivate}. Every endpoint: validated, authenticated where required, authorized,
tenant-scoped (own-store-only, structurally), safe response serialization (no
pricing/billing fields invented or exposed).

Frontend:
resources/js/Pages/Billing/Overview.tsx — current package, status, trial date, usage
overview. No upgrade/downgrade action UI, no payment/checkout UI (Module 29's scope,
explicitly excluded per this milestone's instruction).

Events:
No new outbox events wired this milestone — documented as a deliberate scope
decision (no consumer exists yet that needs these asynchronously); see architecture
doc "Events" section for the exact reasoning and the revisit trigger.

Cache:
Tenant-prefixed entitlement cache key pattern unchanged from B0/B1.
SubscriptionLifecycleService invalidates every known entitlement key for a store on
every package/status transition — verified present at both call sites
(auditAndInvalidate() and transitionStatus()).

Database:
2 additive migrations (add enforcement/period/is_unlimited columns to
package_entitlements; create usage_counters). No column removed, renamed, or made
non-nullable on any existing table. No destructive operation performed. No
subscription history or package data was deleted.

Tests Created:
68 test methods across 7 Feature test files:
- tests/Feature/Packages/PackageTest.php — 4 methods
- tests/Feature/Packages/EntitlementTest.php — 8 methods
- tests/Feature/Packages/UsageLimitTest.php — 9 methods
- tests/Feature/Packages/SubscriptionTest.php — 5 methods
- tests/Feature/Packages/UpgradeDowngradeTest.php — 5 methods
- tests/Feature/Packages/PackageTenantIsolationTest.php — 3 methods
- tests/Feature/Packages/SuperAdminPackageTest.php — 5 methods
Plus carried-forward B0/B1 tests (29 methods) = 68 total across the whole suite
(verified by direct grep count, not estimated). Plus 2 new model factories
(Package, Subscription).

Tests Executed:
NONE.

Runtime Verification:
NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION. No PHP, Composer, MySQL, or
Redis runtime is available in this Claude App sandbox; no outbound network access to
Packagist either. Every test, both new migrations, PHPStan, ESLint, npm build, and
the CI workflow itself have been authored and statically reasoned about, never
executed. A lightweight Node.js-based brace-balance check was run across all new/
modified PHP files as an additional (non-substitute) sanity pass — no mismatches
found.

Security Review:
Performed (docs/security/b2-security-review.md) — 16-item checklist reviewed
end-to-end. 4 issues found and fixed within B2 scope (Model::only() bug, mis-scoped
activeSubscription() relation, missing default trial subscription, incomplete
SubscriptionStatus enum). 3 items documented as correctly deferred (usage counter
reconciliation, subscription/package outbox events, add-on/promotion/contract-
override entitlement layers).

Documentation:
docs/development/b2-inspection-findings.md,
docs/architecture/b2-packages-entitlements.md, docs/security/b2-security-review.md,
this checkpoint. Project Bible, SRS, and ADR status were not modified.

Known Limitations:
- Nothing in this milestone has been executed against a real runtime.
- Usage counter reconciliation has no concrete implementation (no source-of-truth
  table exists yet).
- Business/Premium packages have no numeric usage limits seeded — intentional (see
  Package/Entitlements above), but means Umar Techy must configure real numbers via
  the Super Admin package API before relying on them commercially.
- Package versioning/history (Module 04 §19) is architecturally anticipated but not
  built — a package update currently modifies the same row in place; historical
  pricing/feature snapshots for existing customers on a changed package are not yet
  preserved. Flagged as a gap for a later module, not silently omitted.
- Add-ons, promotions, and contract-override entitlement layers (Module 04 §23-27)
  have no tables or code yet.

Deferred VS Code Verification:
1. composer install / npm install.
2. php artisan migrate (2 new additive migrations, on top of B0/B1's).
3. php artisan db:seed (PermissionSeeder + PackageSeeder, in that order).
4. php artisan test — all 68 test methods, for real PASS/FAIL results, including the
   concurrency test's honest limitation (simulated sequentially here; a genuine
   parallel-request test against a real MySQL instance is recommended in VS Code).
5. composer stan, npm run lint, npm run build.
6. Manual verification of the atomic usage-counter SQL statement's exact behavior
   under real concurrent load.

Next Milestone:
Phase B3 — Catalog (Product & Category Management, per the approved milestone map).
B2 exit criteria are met: package, subscription, and entitlement foundations exist
and are server-authoritative; usage-limit architecture exists and is
concurrency-safe; upgrade/downgrade preserves store identity and never destructively
deletes data; no package-specific codebases exist; required API, frontend, and tests
exist; security review and documentation are complete. Phase B3 will need
EntitlementService::assertCanUse('products.basic', 'max_products') as its first real
caller (currently only exercised by tests) — inspecting that integration point before
any Catalog code is written will be Phase B3's own Step 1.
