============================================================
PHASE B18 CHECKPOINT
============================================================

Phase:
Development Phase B

Module:
B18 — API & Developer Platform (Module 31)

Implementation Summary:
Implemented a Sanctum-independent, hash-verified API key credential system
and a deliberately read-only /api/dev/v1/... developer API, resolving a
direct conflict between Module 31's own text (which names OAuth 2.0 and
JWT/service tokens) and ADR-002/this milestone's own Section 4 (Sanctum
canonical, explicitly forbidding JWT/OAuth) in favor of the higher-priority,
task-specific instruction. Every developer-facing read endpoint reuses the
existing B3/B5/B6 domain models directly - no duplicate business logic, no
new pricing/state-machine code. Webhooks reuse the existing Outbox
(ADR-004) as their sole trigger source, added as a second consumer beside
B11's NotificationEventRouter inside the same, minimally-modified
ConsumeOutboxEventJob. Rate limiting integrates B17's ConfigService (a new
platform setting) rather than inventing a second configuration mechanism.
Runtime execution remains deferred to VS Code - nothing in this milestone
has been executed against a real PHP/MySQL/Redis runtime.

Critical Conflict Identified and Resolved:
Module 31 §4.4 names OAuth 2.0 and JWT/service tokens as authentication
mechanisms. This milestone's own Section 4 and ADR-002 explicitly and
repeatedly forbid introducing either. Resolution: implement ONLY the "API
keys for approved server-to-server integrations" mechanism Module 31 itself
also names - the one option consistent with the platform's already-
established, ADR-governed authentication boundary. Documented in full in
docs/development/b18-inspection-findings.md.

Bugs Found and Fixed (design-time, caught before being left in the codebase):
1. The webhook_delivery_attempts migration originally enforced
   unique(subscription, idempotency_key) - which would have made it
   impossible to record more than one delivery ATTEMPT (a retry) for the
   same underlying event, since both attempts legitimately share the same
   idempotency key. Fixed by scoping uniqueness to
   (subscription, idempotency_key, attempt_number) instead, with
   prior-success idempotency checked at the application level - the same
   pattern this codebase already uses for every other append-only ledger
   since Phase B7's PaymentTransaction.

Architectural Decisions:
- API keys only - never OAuth 2.0, JWT, or mTLS (see Critical Conflict
  above).
- Deliberately READ-ONLY developer API this milestone: 5 scopes
  (products:read, categories:read, orders:read, customers:read,
  inventory:read), every one drawn from Module 31's own listed examples.
  Write scopes are explicitly deferred - order/payment/inventory mutations
  each carry business-invariant risk no concrete requirement resolves
  precisely enough to expose safely yet.
- ApiKeyContext is a request-scoped holder deliberately separate from
  Sanctum's own $request->user() - a Developer Application's API key is not
  a User at all.
- Webhooks reuse the existing Outbox unchanged - WebhookEventRouter is
  added BESIDE (never replacing) NotificationEventRouter in the same
  ConsumeOutboxEventJob; only existing outbox event names are ever
  subscribable.
- SSRF protection for webhook URLs is real (PHP's own
  FILTER_FLAG_NO_PRIV_RANGE/FILTER_FLAG_NO_RES_RANGE), but explicitly
  documented as time-of-check, not a complete defense against DNS
  rebinding - a named residual risk, not hidden.
- Rate limiting integrates B17's ConfigService via one new platform setting
  (api.default_rate_limit_per_minute), which required adding a genuinely
  new SettingType::Integer case to B17's own fixed type list - a minimal,
  additive extension, not a redesign.
- developer_platform.manage is withheld from the default Manager role
  (Owner-only) - the same sensitivity precedent as domains.manage (B14).

Developer Applications:
DeveloperApplication (tenant-owned) with a full lifecycle - create,
suspend, reactivate, revoke (cascades to revoke all its own API keys in
the same transaction, so a revoked application can never leave an active
key behind).

API Keys:
ApiKey - key_prefix (non-secret lookup) + key_hash (SHA-256, plaintext
never stored) + scopes (validated against a fixed ApiScope enum) + full
lifecycle (issue/rotate/revoke/expire). The plaintext secret is returned
exactly once, at issue/rotate time, never logged, never re-displayable.

API Scopes:
5 read-only scopes (see Architectural Decisions). Enforced by a dedicated
EnsureApiScope middleware, always running after authentication, never a
substitute for it.

Rate Limiting:
Per-API-key (never bare IP), via Laravel's own real distributed-cache-
backed RateLimiter, limit value sourced from B17's ConfigService.

Usage Tracking / Request Logging:
ApiRequestLog - metadata only (method/endpoint/status/duration), never
request/response bodies, never the Authorization header.

Webhooks:
WebhookSubscription (SSRF-checked URL, HMAC signing secret shown once) +
WebhookDeliveryAttempt (append-only ledger). DispatchWebhookJob signs every
payload, checks for prior successful delivery before sending (replay
protection), and never serializes the signing secret into its own queue
payload.

Developer API Endpoints:
GET /api/dev/v1/products[/{id}], /orders[/{id}], /customers[/{id}],
/inventory - all read-only, all scope-gated, all tenant-isolated via the
authenticated API key's own store_id.

Staff Management APIs:
Full CRUD for applications/keys/webhooks under /api/v1/developer/... -
tenant-scoped via the existing staff.principal group and BelongsToTenant.

Super Admin Integration:
SuperAdminDeveloperPlatformController reuses B16's existing
super_admin.platform route group unchanged - platform-wide visibility +
suspend, every mutation through ApplicationService, never a direct
database write.

Database:
5 new migrations: developer_applications, api_keys, api_request_logs,
webhook_subscriptions, webhook_delivery_attempts (all new tables). Plus one
additive change to B17's settings.php SettingType enum (+Integer case, no
existing case altered). No existing table's existing column altered,
renamed, or removed. No destructive operation performed.

Cache:
Rate-limit counters only (Laravel's own RateLimiter, keyed by ApiKey id) -
no other new caching introduced.

Events/Jobs:
developer.application.created/suspended/revoked, developer.api_key.created/
revoked - all via the existing, unmodified RecordsOutboxEvents mechanism.
DispatchWebhookJob (new) - tenant-safe (re-resolves TenantContext from the
subscription's own store_id), idempotent (checks the delivery ledger before
sending), retry-safe (Laravel's own queue backoff).

Audit Changes:
developer.api_key.issued/revoked/rotated logged via the existing
Log::channel('audit') mechanism (staff actions); super_admin.
developer_application.suspended logged the same way B16's own Package/
Theme/Setting controllers already do (Super Admin actions).

Security Findings:
See docs/security/b18-security-review.md - a full checklist across
Credential Security/Authorization/Rate Limiting/API Security/Webhooks/
Secrets/Versioning/Infrastructure, plus a B0-B17 regression confirmation
via git diff (zero changes to EnsureCustomerPrincipal/EnsureStaffPrincipal/
EnsureSuperAdminImpersonation; a minimal 9-line additive diff to
ConsumeOutboxEventJob).

Security Fixes:
The one bug listed above; the security review itself surfaced one
DOCUMENTED (not silently accepted) residual risk - SSRF's time-of-check
limitation - rather than a fix, since a complete delivery-time re-check was
judged out of this milestone's bounded scope and is named explicitly as a
known limitation instead.

Tests Created:
37 new test methods across 7 Feature test files:
- tests/Feature/DeveloperPlatform/ApiKeyServiceTest.php - 10 methods
- tests/Feature/DeveloperPlatform/WebhookServiceTest.php - 6 methods
- tests/Feature/DeveloperPlatform/DeveloperApiAuthenticationTest.php - 6 methods
- tests/Feature/DeveloperPlatform/DeveloperApiScopeTest.php - 3 methods
- tests/Feature/DeveloperPlatform/DeveloperPlatformAdminTest.php - 6 methods
- tests/Feature/DeveloperPlatform/SuperAdminDeveloperPlatformTest.php - 3 methods
- tests/Feature/DeveloperPlatform/DispatchWebhookJobTest.php - 3 methods
Plus 2 new model factories (DeveloperApplication, WebhookSubscription).
Combined with all carried-forward B0-B17 tests: 645 test methods total
across the whole suite (verified by direct grep count, not estimated).

Tests Actually Executed:
NONE. No PHP, Composer, MySQL, or Redis runtime is available in this Claude
App sandbox.

Tests Not Executed:
All 645 test methods, including all 37 new to this milestone. The SSRF
tests are deliberately scoped to IP-literal URLs only (no live DNS
resolution is available in this sandbox) - hostname-based SSRF cases are
not exercised here, honestly noted in the test file's own docblock.

Static Inspections Performed (EXECUTED vs INSPECTED vs NOT EXECUTED - nothing
below was EXECUTED):
- Complete inventory of existing /api/v1/, /api/v1/public/, /api/v1/customer/
  routes and a confirmed-empty /api/dev/v1/ namespace before any new code
  was written - including discovery of a pre-existing B0/B1 placeholder
  file (routes/api_dev_v1.php) explicitly reserved for this exact milestone.
- Source inspection of every new/modified file against Module 31's
  requirements.
- A Node.js-based brace/parenthesis balance check across all new/modified
  PHP files - no mismatches found.
- git diff inspection confirming EnsureCustomerPrincipal, EnsureStaffPrincipal,
  and EnsureSuperAdminImpersonation are byte-for-byte unchanged, and
  ConsumeOutboxEventJob's change is minimal and additive.
- BelongsToTenant trait inspection confirming route-model-binding correctly
  enforces tenant isolation for every new model without additional
  controller-level scoping code.
- Manual trace of the platform-mode global-scope bypass (TenantContext::isPlatform())
  confirming SuperAdminDeveloperPlatformController's route-model-binding
  behaves correctly under Super Admin's platform-global context.

Known Limitations:
- Nothing in this milestone has been executed against a real runtime.
- SSRF protection is time-of-check, not time-of-use (DNS rebinding is a
  named residual risk).
- Rate-limit configuration is a single global value, no per-application/
  per-store override.
- No API documentation/OpenAPI generation exists (no tooling installed).
- No separate webhook delivery-worker pool or circuit breaker beyond the
  job's own retry/backoff.

Deferred Dependencies:
OAuth 2.0, JWT/service tokens, mTLS (ADR-002); write scopes for orders/
customers/inventory and any payment operation; Partner API/Marketplace App
API (Module 27 absent); GraphQL/gRPC; OpenAPI schema generation; sandbox/
production credential separation; bulk operations; file/media APIs;
reporting/analytics API exposure; API documentation generation; admin-
facing UI. Full list with rationale in
docs/development/b18-inspection-findings.md.

Files Changed:
New: app/Domain/DeveloperPlatform/ (Models: DeveloperApplication, ApiKey,
ApiRequestLog, WebhookSubscription, WebhookDeliveryAttempt,
ApplicationStatus, ApiKeyStatus, ApiScope, WebhookSubscriptionStatus;
Services: ApplicationService, ApiKeyService, WebhookService,
WebhookEventRouter; Jobs: DispatchWebhookJob; Support: ApiKeyContext;
Policies: DeveloperPlatformPolicy; Http/{Controllers: 7 classes; Requests:
3 classes; Resources: 7 classes}; Exceptions: 3 classes). New:
app/Http/Middleware/{EnsureApiKeyAuthenticated, EnsureApiScope,
LogApiRequest}.php,
app/Domain/SuperAdmin/Http/Controllers/SuperAdminDeveloperPlatformController.php,
5 migrations, 2 factories, 7 test files. Modified:
app/Domain/Events/Jobs/ConsumeOutboxEventJob.php (+WebhookEventRouter call,
additive), app/Domain/Settings/Models/SettingType.php (+Integer case),
app/Domain/Settings/Services/{SettingValidator,SettingRegistry}.php
(+integer validation, +api.default_rate_limit_per_minute), bootstrap/app.php
(+3 middleware aliases, wired the pre-existing api_dev_v1.php placeholder),
app/Providers/AppServiceProvider.php (+ApiKeyContext binding, +rate limiter
registration), routes/api_dev_v1.php (filled in, was a placeholder),
routes/api_v1.php (+developer platform staff routes, +super-admin developer
platform routes), PermissionSeeder (+developer_platform.view/manage),
StoreObserver (+developer_platform.view for Manager, .manage withheld).

Git Status:
Verified by direct execution (git status) before this checkpoint was
written: all files listed above are new/modified/staged relative to the
previous commit (1947a3b / e8e0489). Confirmed via git diff that
EnsureCustomerPrincipal.php, EnsureStaffPrincipal.php, and
EnsureSuperAdminImpersonation.php are byte-for-byte unchanged, and
ConsumeOutboxEventJob.php's change is minimal (9 insertions, 1 deletion).

Git Commit Status:
Commit created: 0573c0f - "Phase B18: API & Developer Platform (Module 31)".
Verified by direct execution (git log --oneline after the commit): working
tree clean, history now shows twenty-six real commits: 5dcb815 (Phase
B0-B5), b12ae2b (B5 checkpoint correction), 47d6a1c (Phase B6), 24b7bd3 (B6
checkpoint correction), 66d66a5 (Phase B7), 6888a16 (B7 checkpoint
correction), 3184400 (Phase B8), 3259813 (B8 checkpoint correction), e781aad
(Phase B9), dc065c9 (B9 checkpoint correction), b62c512 (Phase B10), 3bf153d
(B10 checkpoint correction), bb08fc8 (Phase B11), 7f9ac4b (B11 checkpoint
correction), 4788f9d (Phase B12), e4914ba (B12 checkpoint correction),
1a0d391 (Phase B13), 9207c7f (B13 checkpoint correction), 69edb67 (Phase
B14), ae69918 (B14 checkpoint correction), 2675533 (Phase B15), 3fde434 (B15
checkpoint correction), 41800af (Phase B16), 3db50ad (B16 checkpoint
correction), 1947a3b (Phase B17), e8e0489 (B17 checkpoint correction),
0573c0f (this milestone). No fabricated incremental history.

Final Status:
PASS. A genuine, higher-authority conflict (Module 31 text vs ADR-002) was
identified and resolved per this milestone's own explicit instruction,
documented rather than silently compromised. All new capability is
additive and tenant-safe; one design-time bug was found and fixed; every
prior phase's own authentication boundary is confirmed untouched.

Recommended Next Milestone:
Phase B19 - per the approved module sequence, and given B16/B17 each
identified Modules 20 (Hosting & Infrastructure) and 23 (Backup & Restore)
as the remaining dependency-blocked gaps, either is a natural next
candidate now that Module 31 (this milestone) is built. Module 23 (Backup,
Restore & Data Protection) may be the more urgent of the two from a pure
risk-management standpoint - this platform has accumulated eighteen
milestones of tenant data with no documented backup/restore story at all.
Phase B19's own Step 1 should inspect this checkpoint and
docs/development/b18-inspection-findings.md before deciding, and should
pay particular attention to this milestone's own explicit warning: "do not
implement fake restore operations... if actual infrastructure integration
is unavailable, document it honestly" - a real backup/restore phase in this
sandbox will likely surface significant, honestly-documented dependency
gaps rather than a fully working implementation.
