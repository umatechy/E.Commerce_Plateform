# Phase B22 — Security, Audit & Compliance (Module 32)

## Domain Layout

```
app/Domain/Compliance/
  Models/AuditLog.php                 append-only (update/delete throw), no tenant scope
  Models/AuditActorType.php           user | customer | api_key | system | anonymous
  Models/AuditSurface.php             staff | super_admin | customer | developer_api | system | public
  Services/AuditLogger.php            the only writer: record()
  Services/AuditHasher.php            canonical JSON + SHA-256, shared by writer and verifier
  Services/AuditChainVerifier.php     verify(chain), verifyAll()
  Services/AuditRetentionService.php  prune() with chain anchors
  Services/AuditLogQuery.php          whitelisted filters for both listings
  Services/CustomerDataService.php    export() / erase()
  Listeners/RecordAuthenticationEvents.php  Login / Failed / Logout / Lockout
  Console/VerifyAuditChainCommand.php audit:verify {--chain=}
  Console/PruneAuditLogCommand.php    audit:prune (daily 03:10)
  Policies/CompliancePolicy.php       viewAuditLog, managePrivacy
  Exceptions/CustomerErasureBlockedException.php
  Http/Controllers/AuditLogController.php
  Http/Controllers/CustomerPrivacyController.php
  Http/Resources/AuditLogResource.php
app/Domain/SuperAdmin/Http/Controllers/SuperAdminAuditController.php
app/Http/Middleware/AddSecurityHeaders.php
config/compliance.php
```

## Data Model

### `audit_logs`

| Column | Notes |
|---|---|
| `public_id` | ULID; the only id the API exposes (ADR-003) |
| `store_id` | owning store, `NULL` for the platform chain; `restrictOnDelete` |
| `chain_key` / `sequence` | `platform` or `store:{id}`, gap-free per chain; unique together |
| `action` | dotted name, e.g. `privacy.customer_erased` |
| `actor_type`, `actor_id`, `actor_public_id`, `actor_label` | derived server-side; the label (email or key prefix) survives deletion of the actor |
| `impersonator_id`, `impersonator_label` | the Super Admin behind an impersonated action |
| `surface` | where the action came from |
| `subject_type`, `subject_id`, `subject_public_id` | the model acted on |
| `context` | canonical JSON (`LONGTEXT`, see below), secrets redacted |
| `ip_address`, `user_agent` | from the request, if any |
| `previous_hash`, `hash` | SHA-256 chain links |
| `created_at` | no `updated_at`: entries never change |

Indexes serve the filters: `(store_id, created_at)`, `(action, created_at)`,
actor, subject.

### `audit_chain_heads`

One row per chain: `last_sequence`, `last_hash`, and the anchor
(`anchor_sequence`, `anchor_hash`) left by retention pruning.

## Write Path

```
caller ──record(action, context, subject?, storeId?, actor?, platform?)──▶ AuditLogger
  1. store     = platform ? none : storeId ?? TenantContext store
  2. actor     = ApiKeyContext ▸ explicit actor ▸ Auth::user() ▸ console=system ▸ anonymous
     surface   = api key → developer_api; platform staff on a platform or
                 impersonated request → super_admin; user → staff;
                 customer → customer
  3. context   = redact(keys ~ password|secret|token|…) → canonical JSON
  4. DB::transaction (joins the caller's):
       INSERT IGNORE chain head; SELECT … FOR UPDATE
       sequence = last + 1; previous_hash = last_hash
       hash = SHA-256(canonical row)
       INSERT entry; UPDATE head
  5. mirror to Log::channel('audit') (same message + context as before B22)
```

### Why the context is not a JSON column

MySQL normalizes `JSON` values on write (key order, whitespace). The
stored bytes would differ from the hashed bytes. Context is stored
exactly as `AuditHasher::canonicalJson()` produced it: keys sorted at
every depth, fixed flags.

## Verification

`AuditChainVerifier::verify($chain)` walks the chain in sequence order
and reports the first broken sequence with a reason:

| Tampering | Detected as |
|---|---|
| Any hashed column edited | recomputed hash ≠ stored hash ("modified") |
| Entry deleted from the middle | sequence gap |
| Entry re-linked or re-hashed | `previous_hash` ≠ previous entry's hash |
| Tail truncated | chain head's `last_sequence` / `last_hash` ≠ last entry |

It is exposed as `audit:verify` (non-zero exit on a broken chain, for
cron alerts), as `GET /audit-logs/integrity` for a store, and as the
Super Admin all-chain check.

## Retention

`audit:prune` runs per chain under the head's row lock. It deletes every
entry older than `retention_days` and stores the last deleted entry's
sequence and hash as the anchor. The verifier starts from the anchor, so
the first kept entry's `previous_hash` still has something to match.

## Actions Recorded

| Group | Actions | Chain |
|---|---|---|
| Staff identity | `auth.login.succeeded`, `auth.login.failed`, `auth.logout`, `auth.lockout` | platform |
| Customer identity | `customer.registered`, `auth.login.succeeded`, `auth.login.failed`, `auth.logout` | customer's store |
| Privacy | `privacy.customer_data_exported`, `privacy.customer_erased` | store |
| Subscriptions | lifecycle transitions and package changes (`SubscriptionLifecycleService`) | store |
| Backups / restores | `backup.verified`, `backup.failed`, `restore.authorized`, `restore.preflight_failed`, `restore.completed`, `restore.failed` | store, or platform for platform backups |
| Developer platform | `developer.api_key.issued`, `.revoked`, `.rotated` | store |
| Super Admin | `super_admin.*` (users, packages, themes, settings, subscriptions, developer applications, impersonation, platform actions) | platform, or the target store |

## API

| Method | Path | Who | Notes |
|---|---|---|---|
| GET | `/api/v1/audit-logs` | `audit.view` / Owner | own store only; filters `action` (prefix), `actor_type`, `actor`, `subject_type`, `from`, `to`, `per_page` ≤ 100 |
| GET | `/api/v1/audit-logs/integrity` | `audit.view` / Owner | verifies `store:{id}` |
| GET | `/api/v1/customers/{customer}/personal-data` | `privacy.manage` / Owner | export |
| POST | `/api/v1/customers/{customer}/erase` | `privacy.manage` / Owner | `reason`, `confirm_email`; 409 `open_orders` / `already_erased` |
| GET | `/api/v1/customer/personal-data` | the customer (token) | self-service export |
| GET | `/api/v1/super-admin/audit-logs` | platform staff | all stores; `store=<public_id>` or `store=platform` |
| GET | `/api/v1/super-admin/audit-logs/integrity` | platform staff | every chain |

## Configuration (`config/compliance.php`)

| Key | Env | Default |
|---|---|---|
| `audit.retention_days` | `AUDIT_RETENTION_DAYS` | 365 |
| `audit.mirror_to_log` | `AUDIT_MIRROR_TO_LOG` | true |
| `audit.redact_keys` | — | password, secret, token, authorization, cookie, cvv, card_number, key_hash, signature |
| `audit.max_value_length` | — | 1000 |
| `security_headers.hsts` | `SECURITY_HSTS_ENABLED` | true |
| `security_headers.hsts_max_age` | `SECURITY_HSTS_MAX_AGE` | 31536000 |

## Tests

`tests/Feature/Compliance/` — 35 tests:

- `AuditTrailTest` (11): attribution, redaction, immutability, chain
  verification and each tampering case, per-store chains, pruning,
  impersonation, staff and customer login events.
- `CustomerPrivacyTest` (9): export, erasure effects, sign-in blocked
  after erasure, open-order block, confirmation, double erasure, tenant
  isolation, permissions, customer self-export.
- `AuditLogApiTest` (8): store scoping, filters (including literal `%`),
  integrity, permissions, customer token 401, Super Admin listing and
  integrity, staff blocked from platform routes.
- `SecurityHardeningTest` (7): API and admin page headers, headers on
  errors, HSTS over HTTPS only, password policy, `audit:verify`,
  `audit:prune`.
