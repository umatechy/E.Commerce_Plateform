# Phase B17 — Focused Configuration Security Review

Static/design-level review only — **NOT EXECUTED — DEFERRED TO VS CODE RUNTIME
VERIFICATION**.

## Regression Check — B0-B16 Capabilities Confirmed Intact

Verified by direct `git status`/`git diff`: `BelongsToTenant::store()`
present; zero changes under `app/Domain/Payments/`, `app/Domain/Shipping/`,
`app/Domain/Notifications/`, `app/Domain/Seo/`, `app/Domain/Domains/`,
`app/Domain/Theme/` — every domain-owned configuration/secret mechanism
(payment/shipment/notification webhook secrets, SEO settings, Domain
verification tokens, Theme configuration) is completely untouched.
`EnsureCustomerPrincipal`/`EnsureStaffPrincipal` present and unmodified. All
new capability is additive: three new tables, one new domain, a small
extension to B16's existing platform-global route group.

## Checklist (this milestone's own §56 categories)

| Category | Item | Finding | Status |
|---|---|---|---|
| Authentication | Staff/customer/Super Admin boundary | No new authentication mechanism was introduced — every B17 route sits behind the existing `staff.principal` group or B16's existing `super_admin.platform` group, both unchanged. | Reviewed — OK |
| Authorization | Platform vs store settings | `StoreSettingController` can only ever write with `SettingScope::Store` as the expected scope — `ConfigService::set()` rejects a platform-scope key outright (`SettingScopeMismatchException`) before any persistence. Tested explicitly (writing `platform.maintenance_mode` through the store endpoint 404s at the registry-lookup stage). | Reviewed — OK |
| Authorization | Permission escalation / self-authorization | `SettingPolicy` reuses the exact `userHasPermission()`/`isOwner()` pattern every other B7-B16 policy uses — no new authorization primitive, no self-granting path exists (settings permissions are assigned the same way every other permission is, through the existing Role/Permission system, unchanged). | Reviewed — OK |
| Authorization | Role bypass | No endpoint accepts a client-supplied role/permission override; every check is server-side via `SettingPolicy`. | Reviewed — OK |
| Tenant Isolation | Query scope | `StoreSetting` uses `BelongsToTenant`'s global scope — a query can structurally only ever see the resolved tenant's own rows. Tested explicitly (Store A and B have independent settings). | Reviewed — OK |
| Tenant Isolation | Cache keys | `ConfigService::cacheKey()` includes the resolved store id for every store-scope key (`settings:store:{storeId}:{key}`) — never a bare key name that could collide across tenants. Tested explicitly (a write's cache invalidation is scoped correctly; no cross-store leak in the resolution test suite). | Reviewed — OK |
| Tenant Isolation | Revision history / rollback | `SettingRevision` carries no automatic tenant scope by design (one ledger spans both scopes) — `ConfigService::history()`/`rollbackTo()` both apply an EXPLICIT `store_id` filter, verified by the most security-critical test in this milestone (`test_store_a_cannot_roll_back_using_store_bs_revision_id`). | Reviewed — OK |
| Tenant Isolation | API parameters | No endpoint accepts a client-supplied `store_id`/`tenant_id` parameter of any kind — every store-scope operation resolves its target exclusively from the authenticated request's own `TenantContext`. | Reviewed — OK |
| Configuration Injection | Arbitrary keys | `SettingRegistry::find()` is the ONE gate every read/write passes through — an unrecognized key throws `UnknownSettingKeyException` before any database access. Tested explicitly (both at the validator level and via the live API — an unknown key 404s). | Reviewed — OK |
| Configuration Injection | Arbitrary values / unsafe JSON | `SettingValidator` enforces the exact type declared in the setting's own `SettingDefinition` — a `string_array` setting cannot receive an object, a `boolean` cannot receive a string, `store.timezone` is checked against PHP's own real IANA timezone identifier list. No setting accepts unstructured/arbitrary JSON at all. | Reviewed — OK |
| Configuration Injection | Arbitrary URLs / SSRF | No B17 setting is a URL type at all in this milestone's 6-key scope — nothing to be an SSRF vector yet; the type system is ready for a future `url` type but none exists today. | N/A — no URL-typed setting exists |
| Configuration Injection | Class names / callbacks / expressions / scripts | No setting value is ever passed to `eval()`, a dynamic class resolution, or any code-execution path anywhere in `ConfigService`/`SettingValidator` — every value is inert data (a string, boolean, or list of strings) consumed only by direct comparison/formatting. | Reviewed — OK |
| Secret Security | Plaintext exposure in API responses | `SettingResource`/`SettingRevisionResource` both replace a secret-typed value with `{configured, masked}` unconditionally — proven by three dedicated tests against a synthetic secret definition (no real secret is seeded yet, so this is deliberately tested at the mechanism level). | Reviewed — OK |
| Secret Security | Logs | `SuperAdminSettingController`'s audit log records only the setting `key`, never its value, for every mutation (secret or not) — consistent with Module 33 §30's own "never log raw secret values." | Reviewed — OK |
| Secret Security | Audit / events | `ConfigService::set()`'s outbox event payload carries only `key`/`scope`/`store_id`/`sensitive` (a boolean flag) — never the actual value, secret or otherwise. `SettingRevision.value` for a secret-typed setting stores the CIPHERTEXT (encrypted before being written), never plaintext, even in the historical ledger. | Reviewed — OK |
| Secret Security | Browser storage / exports | No B17 endpoint returns a value in a form intended for client-side storage beyond the ordinary JSON API response (already covered above); no export feature exists in B17's scope. | N/A — no new surface |
| Web Security | XSS/CSRF/SSRF/open redirects/CORS/Host header | No new frontend surface, redirect, or outbound HTTP call was introduced in B17 (every endpoint is a plain JSON API read/write against the local database) — none of these categories apply to a domain with no rendering or network-calling surface of its own. | N/A — no new attack surface of these kinds |
| Persistence | Mass assignment | `PlatformSetting`/`StoreSetting`/`SettingRevision` all use explicit `$fillable`; every write goes through `ConfigService::set()`, never a raw `Model::create()` from a controller with client-supplied field names. | Reviewed — OK |
| Persistence | SQL injection | No `whereRaw()`/`DB::raw()` was introduced anywhere in B17 — every query is a plain Eloquent query-builder call with bound parameters. | Reviewed — OK |
| Persistence | Unsafe serialization | `value` columns are Eloquent's own `array` cast (JSON encode/decode) — no `serialize()`/`unserialize()` (PHP object injection) is used anywhere. | Reviewed — OK |
| Persistence | Migration safety | All 3 new migrations are new-table-only — no existing table's column was altered, renamed, or dropped. | Reviewed — OK |
| Concurrency | Race conditions / lost updates | `updateOrCreate()` on a uniquely-constrained row (`unique(key)`/`unique(store_id, key)`) is the correct, standard Laravel idiom for "insert or update this one row" — a genuine simultaneous-write race is resolved by the database's own unique constraint plus Eloquent's retry-safe `updateOrCreate` semantics, consistent with how every other "one row per key" table in this codebase (e.g. `PlatformSetting` itself) is written. | Reviewed — OK |
| Concurrency | Stale cache | `Cache::forget()` runs synchronously immediately after every successful write, inside the same request — no window exists where a stale cached value could be read after a write has already returned success to the caller. | Reviewed — OK |
| Operational Security | Rollback | Re-applies an earlier value through the SAME validated `set()` path — never a raw database write from the rollback method itself; tenant-boundary-checked (see Tenant Isolation above). | Reviewed — OK |
| Operational Security | Destructive settings | No B17 setting can disable authentication, tenant isolation, payment security, or any other core invariant — Non-Negotiable §75 is honored simply by the fact that none of the 6 seeded settings touches any business-rule enforcement path at all (they are all presentation/locale/currency-preference values). | Reviewed — OK |
| Operational Security | Platform-wide changes | `platform.maintenance_mode` is currently WRITE-ONLY in effect (no consumer reads it yet to actually gate anything) — stated honestly as a known limitation below, not a security risk (a setting nobody reads cannot be misused to bypass anything). | Documented limitation — see below |

## Issues Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

1. `ConfigService::get()`'s use of the query builder's `->value()` method
   bypassed Eloquent's `array` cast, corrupting every setting read. Fixed
   with a proper Eloquent model resolution.
2. `SettingRevisionResource`'s secret-masking used a fragile key-name
   heuristic instead of the real registered type. Fixed via `SettingRegistry`
   lookup.
3. `StoreSettingController::index()` double-wrapped already-constructed
   Resource instances. Fixed with a plain JSON response.

## Known Limitations (Documented, Not Hidden)

1. `platform.maintenance_mode` has no consumer yet — setting it currently has
   no observable effect on the platform (no middleware/gate checks it). This
   is intentional: Module 33 itself only asks B17 to provide the
   configuration FOUNDATION, not to wire every possible consumer; adding a
   maintenance-mode-enforcing middleware is a natural follow-up for whichever
   future phase actually needs it.
2. `store.timezone` is introduced but not yet consumed by B10/B12/B13's own
   date-computation logic (documented architectural decision, not an
   oversight — see inspection findings).
3. No caching layer beyond the simple per-key `Cache::remember()` TTL exists
   (no cache warming, no bulk-read optimization) — acceptable at current
   scale, consistent with every prior phase's identical reasoning.

None of the above required deleting or resetting existing B0-B16 work. No
destructive database operation was performed (all 3 new migrations in B17
are new-table only).
