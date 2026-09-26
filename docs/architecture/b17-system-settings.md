# Phase B17 — System Settings & Configuration Architecture (Module 33)

See `docs/development/b17-inspection-findings.md` for the full inspection
report, the Requirement Matrix (Module 33's own §1 objectives, classified),
and three design-time bugs found and fixed.

## Configuration Scope Boundary — What This IS and What It Is NOT

B17 provides a small, deliberately minimal, cross-cutting configuration
system for settings that don't already belong to another domain. It does
**not** touch environment/deployment config (`config/*.php`, untouched),
does **not** duplicate any domain's own configuration (`Package`
entitlements, `Store.payment_webhook_secret`/`shipment_webhook_secret`/
`notification_signing_secret`, `SeoSetting`, `Domain`, `StoreTheme` — all
confirmed untouched by `git diff`), and does **not** invent business
settings no authoritative document names. Only six settings are seeded, every
one drawn directly from Module 33's own named examples.

## Two Tables, Not One With a Nullable Tenant Column

`platform_settings` (`unique(key)`) and `store_settings`
(`unique(store_id, key)`) are separate tables specifically to avoid MySQL's
"multiple NULLs are distinct in a unique index" quirk — Module 33's own
explicit warning against nullable-tenant-ID ambiguity is honored structurally,
not by convention.

## Code-Defined Registry — the Only Recognized Keys

`SettingRegistry::all()` is a fixed, small PHP array — `ConfigService::get()`/
`set()` both reject any key not present here before touching the database at
all (Non-Negotiable §12: "no arbitrary key/value admin"). A future module
adds settings by adding entries to this same registry; there is no client-
writable "define a new key" endpoint anywhere in this codebase.

## Resolution Hierarchy — Deterministic, Two Levels

Store override → platform-key fallback (only for `store.default_locale`,
which explicitly falls back to `platform.default_locale`) → code-defined
default. This collapses Module 33's own longer Environment→Platform→Tenant→
Store→User chain to this platform's actual two-scope model (Tenant and Store
have always been one concept since Phase B1).

## Three Bugs Found During Implementation

1. `ConfigService::get()` originally used the query builder's `->value('value')`
   method, which bypasses Eloquent's own `array` cast entirely — every read
   would have silently corrupted its result (array-indexing a raw JSON
   string instead of the decoded value). Fixed by resolving a real Eloquent
   model (`->first()?->value`) instead.
2. `SettingRevisionResource` originally masked secret values using a fragile
   `str_ends_with($key, '.secret')` heuristic instead of the real registered
   type. Fixed by looking up the actual `SettingDefinition` from
   `SettingRegistry`, the same authoritative source `SettingResource` already
   used correctly.
3. `StoreSettingController::index()` originally double-wrapped already-
   constructed `SettingResource` instances through `SettingResource::collection()`.
   Fixed by returning a plain JSON response instead.

## Secrets — Real Encryption, No Fake Substitute, Nothing Seeded

`SettingType::Secret` uses Laravel's own `Crypt::encryptString()`/
`decryptString()` (backed by the real `APP_KEY`) — genuine, non-fake
encryption already available with zero new dependencies. `SettingResource`/
`SettingRevisionResource` NEVER return a secret's decrypted value in any
ordinary response — only `{configured, masked: "••••••••"}`. No concrete
secret setting is actually seeded (no business need is specified) — this is
capability-only, proven by three dedicated tests against a synthetic
definition.

## Rollback and History — Explicit Tenant Filtering, Never Implicit

`SettingRevision` deliberately carries NO `BelongsToTenant` scope (one ledger
spans both Platform and Store scopes) — `ConfigService::history()` and
`rollbackTo()` both apply an EXPLICIT `where('store_id', ...)` filter for a
store-scope key, never relying on an automatic global scope that doesn't
exist on this model. `rollbackTo()` additionally verifies the target
revision's own `store_id` matches the CURRENT tenant context before allowing
the rollback — tested explicitly (Store A cannot roll back using Store B's
revision id, even if it somehow learned the numeric id).

## Super Admin Integration — Reuses B16 Exactly, No New Gate

`SuperAdminSettingController` sits inside the SAME `super_admin.platform`
route group B16 established for Package/Theme catalog management — no new
authorization boundary, no new audit mechanism; it writes an action-specific
`super_admin.setting.updated` log entry using the identical pattern B16's own
Package/Theme controllers already use.

## API Endpoints Added in B17

| Method | Path | Auth |
|---|---|---|
| GET/PUT | `/api/v1/store/settings[/{key}]` | staff (`settings.view`/`manage`) |
| GET | `/api/v1/store/settings/{key}/history` | staff |
| POST | `/api/v1/store/settings/revisions/{id}/rollback` | staff |
| GET/PUT | `/api/v1/super-admin/settings[/{key}]` | Super Admin (platform-global group) |

## UI

Not built in B17, matching every backend-focused phase's own precedent.

## Deferred (see inspection findings for the full, explicit list)

User/Application/Integration/Feature/System-Service scopes, feature flags (no
concrete flag specified anywhere), package/entitlement-aware configuration
(B2's `EntitlementService` remains sole authority), any theme/domain/payment/
shipping/notification/billing/API/security setting (each remains its owning
domain's responsibility), wiring the new `store.timezone` setting into B10/
B12/B13's actual date-computation call sites (introduced as a setting, not
yet consumed — documented as the natural next integration step for a future
phase).
