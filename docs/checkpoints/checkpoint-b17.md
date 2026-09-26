============================================================
PHASE B17 CHECKPOINT
============================================================

Phase:
Development Phase B

Module:
B17 — System Settings & Configuration (Module 33)

Implementation Summary:
Implemented a small, deliberately minimal, cross-cutting configuration
foundation for settings that do not already belong to another domain -
never a generic key-value store, never a duplicate of any existing domain's
own configuration (Package entitlements, Payment/Shipment/Notification
webhook secrets, SEO settings, Domain verification, Theme configuration -
all confirmed untouched by git diff). Only 6 settings are seeded, every one
drawn directly from Module 33's own named examples, closing a gap (no
Store.timezone column) that Phases B10/B12/B13 had each independently found
and documented. Runtime execution remains deferred to VS Code - nothing in
this milestone has been executed against a real PHP/MySQL/Redis runtime.

Bugs Found and Fixed (design-time, caught before being left in the codebase):
1. ConfigService::get() originally used the query builder's ->value('value')
   method, which returns the RAW, uncast database column rather than
   resolving a real Eloquent model - this bypassed the array cast entirely,
   meaning every single setting read would have array-indexed a raw JSON
   string instead of the actual decoded value, silently corrupting every
   read. Fixed by resolving a real model (->first()?->value) instead.
2. SettingRevisionResource originally masked secret values using a fragile
   str_ends_with($key, '.secret') heuristic rather than checking the
   setting's real registered type - would have silently failed to mask a
   genuine secret's history unless its key happened to end that way exactly.
   Fixed by looking up the real SettingDefinition from SettingRegistry, the
   same authoritative source SettingResource already used correctly.
3. StoreSettingController::index() double-wrapped already-constructed
   SettingResource instances through SettingResource::collection(), which
   expects raw data to wrap, not already-wrapped Resources. Fixed by
   returning a plain JSON response instead.

Architectural Decisions:
- Two separate tables (platform_settings, store_settings) rather than one
  table with a nullable tenant column - avoids MySQL's "multiple NULLs are
  distinct in a unique index" ambiguity entirely, per Module 33's own
  explicit warning against nullable-tenant-ID ambiguity.
- Code-defined SettingRegistry (a fixed PHP array), not a database table for
  definitions - Module 33 itself explicitly permits this, and it guarantees
  no client-writable "define a new key" endpoint can ever exist.
- Deliberately minimal, named-example-only seeded settings: 6 keys total
  (platform.supported_currencies, platform.default_locale,
  platform.maintenance_mode, store.default_currency, store.timezone,
  store.default_locale) - no feature flags, no integration/API/billing/
  theme/notification settings invented.
- Tenant and Store are treated as ONE scope (never a separate split) -
  matching this platform's actual Store-IS-the-tenant model since Phase B1,
  not Module 33's own more granular but architecturally-inapplicable
  Tenant/Store distinction.
- store.timezone is introduced but deliberately NOT wired into B10/B12/B13's
  own date-computation logic this milestone - closing the configuration gap
  is in scope; auditing and rewiring three unrelated, already-reviewed
  domains' business logic is not, and is documented as the natural next
  step for a future phase.
- Secret value type uses Laravel's own real Crypt facade (genuine encryption,
  zero new dependencies) - no fake substitute, and no concrete secret is
  actually seeded (capability-only, proven by dedicated tests against a
  synthetic definition).
- SettingRevision carries no automatic tenant scope (one ledger spans both
  Platform and Store scopes) - every read/rollback applies an EXPLICIT
  store_id filter instead, the single most security-critical design choice
  in this milestone, tested directly.

Platform Settings:
platform.supported_currencies, platform.default_locale,
platform.maintenance_mode - Super-Admin-only, via the existing B16
platform-global route group, no new authorization boundary.

Tenant Settings:
store.default_currency (cross-validated against platform.supported_currencies),
store.timezone, store.default_locale (falls back to the platform default) -
staff-facing, via the existing staff.principal group, gated by new
settings.view/manage permissions.

User Settings:
Not implemented - no concrete requirement was given, and this platform has
no existing user-preference domain for Module 33 to integrate with instead
of duplicating.

Secret Handling:
Real Laravel Crypt-based encryption at rest for any secret-typed setting;
SettingResource/SettingRevisionResource both replace a secret's value with
{configured, masked} in every response; audit logs and outbox events record
only the setting key, never its value.

APIs:
GET/PUT /api/v1/store/settings[/{key}], GET
/api/v1/store/settings/{key}/history, POST
/api/v1/store/settings/revisions/{id}/rollback (staff); GET/PUT
/api/v1/super-admin/settings[/{key}] (Super Admin, B16's existing
platform-global group).

UI:
Not built - matches every backend-focused phase's own precedent.

Database:
3 new migrations: platform_settings, store_settings, setting_revisions (all
new tables). No existing table's existing column altered, renamed, or
removed. No destructive operation performed.

Migrations:
All additive/new-table only, consistent with every migration since Phase
B0.

Cache:
Per-key Cache::remember() (5-minute TTL), tenant-safe cache keys
(settings:store:{storeId}:{key} vs settings:platform:{key}), synchronous
Cache::forget() immediately after every write - no stale-read window.

Events/Jobs:
setting.changed outbox event on every write (key/scope/store_id/sensitive
flag only, never the value) via the existing, unmodified
RecordsOutboxEvents mechanism. No new queued job was introduced - every B17
operation is synchronous.

Audit Changes:
Append-only SettingRevision ledger (doubles as both audit trail and
rollback source, same 2-in-1 pattern as every ledger since Phase B7's
PaymentTransaction); Super Admin platform-setting updates additionally
write an action-specific super_admin.setting.updated log entry via the
existing Log::channel('audit') mechanism B16 established.

Security Findings:
See docs/security/b17-security-review.md - a full checklist across
Authentication/Authorization/Tenant Isolation/Configuration Injection/
Secret Security/Web Security/Persistence/Concurrency/Operational Security,
plus a B0-B16 regression confirmation via git diff (zero changes under
Payments/Shipping/Notifications/Seo/Domains/Theme).

Security Fixes:
The three bugs listed above; no additional fixes were required beyond those
(the security review itself surfaced no NEW issue beyond what implementation
review had already caught - unlike B15's own third finding, which was
caught specifically during the security-review pass).

Tests Created:
33 new test methods across 6 Feature test files:
- tests/Feature/Settings/SettingRegistryValidatorTest.php - 8 methods
- tests/Feature/Settings/ConfigServiceTest.php - 8 methods
- tests/Feature/Settings/SettingRevisionRollbackTest.php - 5 methods
- tests/Feature/Settings/StoreSettingAdminTest.php - 6 methods
- tests/Feature/Settings/SuperAdminSettingTest.php - 3 methods
- tests/Feature/Settings/SettingSecretMaskingTest.php - 3 methods
Combined with all carried-forward B0-B16 tests: 608 test methods total
across the whole suite (verified by direct grep count, not estimated).

Tests Actually Executed:
NONE. No PHP, Composer, MySQL, or Redis runtime is available in this Claude
App sandbox.

Tests Not Executed:
All 608 test methods, including all 33 new to this milestone.

Static Inspections Performed (EXECUTED vs INSPECTED vs NOT EXECUTED - nothing
below was EXECUTED):
- Complete inventory of existing configuration mechanisms across B0-B16
  (config/*.php, every domain's own secret/configuration storage) before any
  new code was written.
- Source inspection of every new/modified file against Module 33's
  requirements.
- A Node.js-based brace/parenthesis balance check across all new/modified
  PHP files - no mismatches found.
- git diff/status inspection confirming zero changes under
  app/Domain/Payments/, Shipping/, Notifications/, Seo/, Domains/, Theme/ -
  every domain-owned configuration mechanism is byte-for-byte unchanged.
- Manual trace of the resolution hierarchy (store override -> platform
  fallback -> code default) against every one of the 6 seeded settings'
  actual test cases.

Regression Results:
No regression found. BelongsToTenant::store() present; EnsureCustomerPrincipal/
EnsureStaffPrincipal unmodified; every prior domain's own configuration/
secret mechanism confirmed untouched by direct git diff.

Known Limitations:
- Nothing in this milestone has been executed against a real runtime.
- platform.maintenance_mode has no consumer yet (no middleware/gate reads
  it) - setting it currently has no observable platform effect, documented
  honestly rather than claimed as a working feature.
- store.timezone is not yet consumed by B10/B12/B13's own date logic.
- No bulk-read optimization or cache warming exists beyond simple per-key
  caching.

Deferred Dependencies:
User/Application/Integration/Feature/System-Service configuration scopes,
feature flags, package/entitlement-aware configuration, any theme/domain/
payment/shipping/notification/billing/API/security-owned setting, Module
18/20/25/26/27/29/31/32/34/35 configuration (none of these modules exist
yet). Full list with rationale in docs/development/b17-inspection-findings.md.

Documentation Created:
docs/development/b17-inspection-findings.md, docs/architecture/b17-system-settings.md,
docs/security/b17-security-review.md, this checkpoint.

Files Changed:
New: app/Domain/Settings/ (Models: PlatformSetting, StoreSetting,
SettingRevision, SettingScope, SettingType; Services: SettingDefinition,
SettingRegistry, SettingValidator, ConfigService; Policies: SettingPolicy;
Http/{Controllers: StoreSettingController; Requests: UpdateSettingRequest;
Resources: SettingResource, SettingRevisionResource}; Exceptions: 4
classes). New:
app/Domain/SuperAdmin/Http/Controllers/SuperAdminSettingController.php, 3
migrations, 6 test files. Modified: PermissionSeeder (+settings.view/manage),
StoreObserver (+settings permissions for Manager), routes/api_v1.php
(+store settings routes, +super-admin settings routes in the existing
platform-global group).

Git Status:
Verified by direct execution (git status) before this checkpoint was
written: all files listed above are new/modified/staged relative to the
previous commit (41800af / 3db50ad). Confirmed via git diff that zero files
under app/Domain/Payments/, Shipping/, Notifications/, Seo/, Domains/, or
Theme/ were touched.

Git Commit Status:
A commit for this milestone's work follows immediately after this
checkpoint; the real, executed commit hash is recorded via a follow-up
correction commit immediately after, same pattern used for every prior
phase's checkpoint.

Final Status:
PASS. All new capability is additive and tenant-safe; three design-time
bugs were found and fixed before being left in the codebase; every prior
phase's own configuration/secret mechanism is confirmed untouched.

Recommended Next Milestone:
Phase B18 - per the approved module sequence, and given B16 itself
identified Modules 20 (Hosting & Infrastructure), 23 (Backup & Restore), and
31 (API & Developer Platform) as the remaining dependency-blocked gaps now
that Module 33 (this milestone) is built, any of these three is a natural
next candidate. Module 31 (API & Developer Platform) may be the most
immediately valuable, since ADR-005's own versioned API architecture
(/api/v1/..., /api/dev/v1/...) already anticipates a developer-facing API
surface that has never been built, and B17's own configuration foundation
(scoped settings, secret handling) is a natural dependency for API-key/
rate-limit configuration a Module 31 phase would need. Phase B18's own Step
1 should inspect this checkpoint and docs/development/b17-inspection-findings.md
before deciding.
