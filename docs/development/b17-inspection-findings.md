# Phase B17 — Step 1: Inspection + Scope Decision (System Settings & Configuration: Module 33)

## Inspection of Existing Configuration Mechanisms

- `config/*.php` (Laravel's own environment-driven config: `auth.php`,
  `outbox.php`, `packages.php`, `tenancy.php`, `seo.php`, `domains.php`) —
  ENVIRONMENT/DEPLOYMENT scope (Module 33 §3.2). These remain exactly where
  they are — none is migrated into a database-backed setting. This is
  Module 33's own explicit Non-Negotiable ("environment configuration must
  remain environment-isolated... never expose APP_KEY/DB_PASSWORD/etc.
  through Settings").
- Domain-owned configuration already exists and is explicitly, correctly
  NOT touched: `Package`/`Subscription`/entitlement values (Module 04, B2),
  `PaymentMethod`/gateway secrets (`Store.payment_webhook_secret`, Module 12,
  B7), `Store.shipment_webhook_secret` (Module 13, B8),
  `Store.notification_signing_secret` (Module 21, B11), `SeoSetting` (Module
  16, B13), `Domain`/`Store.notification_signing_secret` (Module 19, B14),
  `StoreTheme`/`ThemeConfigValidator` (Module 17, B15). Every one of these is
  a genuinely domain-owned configuration mechanism with its own validation,
  ownership, and (where relevant) secret-handling story — Module 33's own
  explicit Non-Negotiable ("Module 33 may provide common infrastructure... it
  must not steal ownership of domain-specific business rules") means NONE of
  these are migrated, wrapped, or duplicated by B17.
- `users.platform_role`/`users.is_active` (B1/B16) — identity/authorization
  state, not configuration; untouched.
- **No generic, cross-cutting, database-backed settings system exists
  anywhere in B0-B16.** This is the genuine gap B17 fills.
- **Confirmed, multiply-documented pre-existing gap**: no `Store.timezone`
  column exists. B10 (`Campaign.scheduled_at`), B12 (`DashboardService`
  date-range math), and B13 (`SeoResolver`/sitemap generation) each
  independently found this gap and each documented the identical UTC-only
  workaround. **B17 closes this gap** by introducing `store.timezone` as a
  genuine, validated Module 33 setting — but does NOT rewire B10/B12/B13's
  own date-computation call sites to consume it in this milestone (see
  Architectural Decision below), keeping this milestone's diff focused on
  the configuration foundation itself rather than expanding into three other
  domains' business logic.
- No regressions found in B0-B16 during inspection.

## B17 Requirement Matrix (Module 33's Own §1 Objectives, Classified)

| Objective | Classification | Notes |
|---|---|---|
| §1.1 One governed configuration architecture | REQUIRED NOW | Core deliverable of this milestone |
| §1.2 Platform/environment/tenant/store/user/application/integration scopes | PARTIALLY IMPLEMENTED | Only Platform + Store (collapsed with Tenant — see below) are built; Environment already exists via Laravel config (untouched); User/Application/Integration scopes are DEFERRED (no concrete requirement given) |
| §1.3 Prevent an uncontrolled key-value store | REQUIRED NOW | The central discipline of `SettingDefinition`'s fixed registry |
| §1.4 Typed, schema-validated configuration | REQUIRED NOW | `SettingDefinition` + `SettingValidator` |
| §1.5 Secure configuration / secret references | REQUIRED NOW (minimal) | A real `secret` value type using Laravel's own `Crypt` facade — no concrete secret is seeded, only the capability |
| §1.6 Defaults, inheritance, overrides, fallback | REQUIRED NOW | Platform → Store hierarchy, deterministic resolution |
| §1.7 Package/entitlement-aware configuration | DEFERRED | No concrete entitlement-gated setting is specified; B2's own `EntitlementService` remains untouched and authoritative for feature access |
| §1.8 Safe changes without code deployment | REQUIRED NOW | The entire point of a database-backed setting |
| §1.9 Versioning and audit history | REQUIRED NOW (minimal) | Append-only `SettingRevision` ledger, same pattern as every ledger since B7 |
| §1.10 Rollback | REQUIRED NOW | Re-applies an earlier revision's value through the same validated write path |
| §1.11 Feature flags / controlled rollout | DEFERRED | No concrete flag is specified anywhere in the authoritative documents; inventing one would violate this milestone's own "do not invent commercial/operational settings" |
| §1.12 Localization/regional settings | REQUIRED NOW (minimal) | `platform.default_locale`, `store.default_locale`, `store.timezone`, `store.default_currency`/`platform.supported_currencies` — Module 33's own named examples |
| §1.13-1.19 Operational/integration/theme/API/notification/billing/security settings | NOT IN SCOPE THIS MILESTONE | Each remains owned by its existing domain (B7/B8/B11/B15/B17 itself does not touch B16's Super Admin, Module 31/29/32 don't exist) — B17 provides the infrastructure a future phase COULD use, never takes ownership |
| §1.20 Future-module extensibility | REQUIRED NOW | Achieved structurally: any future module registers its own `SettingDefinition` entries against the same `ConfigService`, without changing its core |

## Architectural Decision — Tenant Scope and Store Scope Are One Scope

Module 33 §3.3-3.4 lists "Tenant Scope" and "Store Scope" as distinct. This
platform's actual data model has never made that distinction — `Store` IS
the tenant (one-to-one, established since Phase B1, reused unchanged by every
phase through B16). B17 does not invent a new tenant/store split that
contradicts twenty phases of established architecture; `store_settings` (see
below) serves both roles as one scope, exactly mirroring how `BelongsToTenant`
itself has always worked.

## Architectural Decision — Two Separate Tables, Not One with a Nullable Tenant Column (Module 33 §25, "Avoid Nullable Tenant IDs Where That Creates Ambiguity")

A single `settings` table with a nullable `store_id` (NULL = platform scope)
would need `unique(store_id, key)` for correctness — but MySQL treats
multiple NULLs as DISTINCT in a unique index, so nothing would actually
prevent two rows for the same platform-scope key. Module 33's own explicit
warning against nullable-tenant-ID ambiguity is honored by using two
separate, strongly-unique tables instead: `platform_settings` (`unique(key)`)
and `store_settings` (`unique(store_id, key)`) — both trivially strict, no
NULL-uniqueness edge case at all.

## Architectural Decision — Code-Defined Setting Definitions, Not a Database Table

Module 33 §10 explicitly permits this ("do not necessarily create a database
table for definitions if the architecture specifies code-defined schemas").
`SettingDefinition` is a small, fixed PHP registry (key → type, scope,
default, validation rule, sensitivity) — server-authoritative, cannot be
altered by any API call, and trivially reviewable in one file. A future
module adds its own settings by adding entries to this same registry, never
by a client-writable "define a new key" endpoint (which Module 33 itself
forbids outright — §12, "no arbitrary key/value admin").

## Architectural Decision — Minimal, Named-Example-Only Seeded Settings

Per this milestone's own repeated instruction ("do not invent commercial or
operational settings... do not blindly implement every objective"), B17
seeds exactly six settings, every one drawn directly from Module 33's own
named examples (§3.1 "supported currencies", "supported languages"; the
platform's own multiply-documented timezone gap):

| Key | Scope | Type | Default |
|---|---|---|---|
| `platform.supported_currencies` | platform | array (ISO 4217 codes) | `["USD"]` |
| `platform.default_locale` | platform | string (locale code) | `"en"` |
| `platform.maintenance_mode` | platform | boolean | `false` |
| `store.default_currency` | store | string | falls back to `platform.default_locale`... falls back to `"USD"`; must be one of `platform.supported_currencies` (cross-field validation) |
| `store.timezone` | store | string (IANA timezone) | `"UTC"` |
| `store.default_locale` | store | string | falls back to `platform.default_locale` |

No feature flags, no integration/API/billing/theme/notification settings are
seeded — each remains its owning domain's responsibility, per the Requirement
Matrix above.

## Architectural Decision — `store.timezone` Is Introduced but NOT Wired Into B10/B12/B13 This Milestone

Adding `store.timezone` as a real, validated, resolvable setting closes the
documented gap those three phases each independently found. Actually
rewriting `CampaignService`'s scheduling math, `DashboardService`'s date-range
math, and `SeoResolver`'s sitemap generation to CONSUME this new setting is
explicitly deferred — this milestone's own instruction is "provide the
configuration foundation," not "audit and rewire every existing UTC-only date
computation across three unrelated domains," which would substantially
expand this milestone's diff beyond its own stated purpose and risk
regressing three already-reviewed, already-tested domains for a change not
requested by any of the module documents governing B10/B12/B13 themselves.
Documented here as the natural next integration step for a future phase,
exactly as B14 documented `SeoResolver`'s own canonical-URL placeholder before
B14 itself came along to fix it.

## Architectural Decision — Secret Value Type Uses Laravel's Real `Crypt` Facade, No Concrete Secret Seeded

Module 33 §16 explicitly forbids "fake encryption or an insecure substitute."
Laravel's own `Crypt::encryptString()`/`decryptString()` (backed by the
application's real `APP_KEY`) is a genuine, non-fake encryption mechanism
already available in this Laravel application with zero new dependencies.
`SettingDefinition` supports a `secret` type: on write, the value is
encrypted before storage (`platform_settings.value`/`store_settings.value`);
on read via the ordinary `ConfigResource`, a `secret`-typed setting is ALWAYS
represented as `{configured: true, masked: "••••••••"}`, never the decrypted
value — the raw value is only ever decrypted internally by
`ConfigService::get()` for a caller that already holds it server-side (never
returned through any HTTP response). No concrete secret setting is actually
seeded in B17 (no business need for one is specified) — this is
capability-only, exactly matching this milestone's "provide the boundary,
don't invent the business use."

## Scope Decision Summary

**B17 implements**: `platform_settings`/`store_settings` tables,
`SettingDefinition` (code-defined registry, 6 seeded keys), `SettingValidator`
(type/range/enum/cross-field validation, no arbitrary key ever accepted),
`ConfigService` (the one resolution point — Platform default → Store
override → effective value, deterministic, cached), `SettingRevision`
(append-only audit/rollback ledger), rollback (re-applies an earlier
revision through the same validated write path), outbox events for setting
changes, and staff-facing (store scope) + Super-Admin-facing (platform scope)
APIs, reusing B16's existing platform-global route group unchanged.

**Explicitly deferred** (named so nothing is silently dropped): User/
Application/Integration/Feature/System-Service scopes (§3.5-3.9, no concrete
requirement given), feature flags (§1.11, no concrete flag specified),
package/entitlement-aware configuration (§1.7, B2's `EntitlementService`
remains the sole authority on feature access), any theme/domain/payment/
shipping/notification/billing/API/security setting (each remains its owning
domain's — B7/B8/B11/B14/B15/B16 — untouched responsibility), wiring
`store.timezone` into B10/B12/B13's actual date computations (introduced as a
setting, not yet consumed), Module 18/20/25/26/27/29/31/32/34/35 configuration
(none of these modules exist yet to configure).

## Bug Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

`ConfigService::get()` originally used the query builder's `->value('value')`
method to read a setting row — this method returns the RAW, uncast database
column value (a JSON string), bypassing Eloquent's own `array` cast on
`PlatformSetting`/`StoreSetting` entirely. The subsequent `$raw[0]` access
would therefore have array-indexed a STRING (returning its first character,
not the stored setting value) rather than the actual decoded array element,
silently corrupting every single setting read. Caught before being left in
the codebase — fixed by using `->first()?->value` instead, which resolves a
real Eloquent model and correctly applies its `array` cast.

## Second Bug Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

An early draft of `SettingRevisionResource` masked secret values using a
fragile heuristic (`str_ends_with($key, '.secret')`) rather than actually
checking the setting's real registered type. Since no secret setting is
currently seeded this heuristic is not exercised today, but it would have
silently failed to mask a genuinely secret-typed setting's history the
moment one was added, unless its key happened to literally end in
`.secret`. Fixed by looking up the real `SettingDefinition` from
`SettingRegistry` — the same authoritative source `SettingResource` already
uses correctly — before deciding whether to mask.

## Third Bug Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

`StoreSettingController::index()` originally called
`SettingResource::collection($resources)` where `$resources` was already a
Collection of individually-constructed `SettingResource` instances (each
built from a `[definition, effectiveValue]` pair via `->map()`) — Laravel's
`::collection()` helper expects raw models/data to wrap, not already-wrapped
Resource objects, which would have double-wrapped every entry incorrectly in
the JSON output. Fixed by returning a plain `response()->json(['data' =>
$resources])`, since each item was already the correctly-shaped
`SettingResource` instance.
