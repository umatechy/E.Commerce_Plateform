# Phase B22 — Step 1: Inspection + Gap Analysis (Module 32)

Module 32 (Security, Audit & Compliance) is the module most often
referenced by earlier phases and never built. Every phase from B2 onward
wrote "audit" lines, and ADR-004 names the audit record as the one write
that must stay synchronous. This inspection lists what existed before B22,
what was missing, and what B22 decided.

## What Existed

| Area | State before B22 | Problem |
|---|---|---|
| Audit trail | 26 `Log::channel('audit')->info(...)` calls writing to `storage/logs/audit-*.log` (the channel itself was only added in B21) | Not queryable per store; no actor, surface or subject in a fixed shape; a file anyone with shell access can edit silently; rotated away by log retention; not transactional, so a rolled-back action could still leave a "done" line |
| Authentication events | None recorded | No trail of failed logins, lockouts or logouts for either staff or customers |
| Customer personal data | Spread over customers, orders (guest fields, address snapshots, notes), notification messages, wishlist items, tokens | No export (right of access) and no erasure (right to be forgotten) path at all |
| HTTP security headers | None | No `nosniff`, framing protection, referrer policy or HSTS on any response |
| Password policy | `Password::defaults()` used by both registration requests, never configured (Laravel's default: 8 characters, anything) | `12345678` was an acceptable owner password |
| Audit read access | None | Neither a store owner nor the Super Admin could see "who did what" without server access |

## Gaps Closed in B22

1. **A tamper-evident, queryable audit store.** `audit_logs` is
   append-only at the model level (update/delete throw) and each entry is
   linked into a SHA-256 hash chain. There is one chain per store
   (`store:{id}`) plus one `platform` chain, so a store's trail can be
   verified and exported on its own and one busy store never serializes
   another's writes.
2. **One writer.** `AuditLogger::record()` is the only code that writes
   audit entries. Callers state only *what* happened (action, context,
   subject). Who acted (user, customer, API key, system or anonymous),
   through which surface (staff, super_admin, customer, developer_api,
   system, public), for which store and under which impersonation all
   come from the authenticated principal, `ApiKeyContext` and
   `TenantContext`. A caller cannot forge them.
3. **Transactional.** The write joins the caller's transaction. An
   action that rolls back leaves no entry saying it happened (ADR-004).
4. **Secrets never stored.** Keys matching `password`, `secret`, `token`,
   `authorization`, `cookie`, `cvv`, `card_number`, `key_hash` or
   `signature` are replaced with `[redacted]` at every depth. Long values
   are cut to 1000 characters. Enums and dates are normalized.
5. **All 26 existing call sites migrated,** and each now names its
   subject and owning store where one exists (subscription lifecycle,
   backups, restores, API keys, developer applications, Super Admin
   user, package, theme, subscription and store actions). The two Super
   Admin middlewares (platform action, impersonation) also write through
   it.
6. **Authentication auditing.** Staff login success, failure (email only,
   never the password), logout and lockout go to the platform chain.
   Staff users are not owned by any one store. Customer registration,
   login success, failure and logout go to the customer's store chain.
7. **Data-subject requests.** Staff with `privacy.manage` can export a
   customer's data and erase it. The customer can export their own data.
   Every request is audited, and the audit entry never copies the
   exported data.
8. **Retention that keeps the chain verifiable.** `audit:prune` (daily
   03:10) deletes entries older than `AUDIT_RETENTION_DAYS` (default 365).
   It records the hash of the last deleted entry as the chain's anchor,
   so the entries that remain still verify end to end.
9. **Security headers and password policy** (see
   docs/security/b22-security-review.md).

## Design Decisions

### Canonical JSON in a TEXT column, not a JSON column

MySQL's `JSON` type normalizes documents on write: it reorders object
keys and rewrites whitespace. The stored bytes would then differ from the
bytes that were hashed, and every chain would verify as broken. Context
is therefore canonicalized by `AuditHasher::canonicalJson()`, with keys
sorted at every depth, and stored as `LONGTEXT`. Writer and verifier
share `AuditHasher`, so the hashing rules cannot drift apart.

### Per-chain row lock, not a global lock

`audit_chain_heads` holds each chain's last sequence and hash. An append
locks that one row (`SELECT … FOR UPDATE`), so sequence numbers are
gap-free and links are correct under concurrency. Stores never contend
with each other.

### Anonymize, never delete, on erasure

Orders are financial records that must be retained (ADR-003). Erasure
therefore:

- clears the guest fields and notes on the customer's orders, and reduces
  address snapshots to `{country, redacted: true}` (country is kept for
  tax and revenue reporting);
- blanks notification destinations, subjects and bodies;
- deletes wishlist items and revokes all tokens;
- replaces the customer's name, email and phone. The email becomes
  `erased+{public_id}@erased.invalid`, which stays unique and is
  undeliverable. The password is removed, so the account can never be
  signed into again.

Order totals, line items and the `customer_id` link stay, so revenue,
analytics and refunds keep working. Erasure is refused (409) while the
customer has an order still in progress (draft through refund_pending),
because fulfilment needs the address.

### Erasure confirmation

The request must restate the customer's email (`confirm_email`,
case-insensitive) and give a `reason`. The reason is kept in the audit
entry.

### Who may read the audit trail

- A store's trail: `audit.view` or the Owner.
- Data-subject requests: `privacy.manage` or the Owner.
- The seeded Manager role gets neither, following the same precedent as
  `backups.restore` and `domains.manage`.
- Super Admin: platform-wide listing and integrity check, inside the
  `super_admin.platform` group. That group's middleware audits the read
  itself.

## Bugs Found During the Build (fixed before commit)

| Bug | Cause | Fix |
|---|---|---|
| Test suite hung, chain head updated endlessly | The bulk call-site replacement also rewrote the logger's own log mirror line into a call to itself | Mirror line restored to `Log::channel('audit')`. The mirror keeps the pre-B22 log message and context, so existing log shipping works unchanged |
| Super Admin platform test failed after adding auth auditing | `Event::subscribe()` builds the subscriber once at boot, so a constructor-injected `AuditLogger` held the boot-time `TenantContext`. Every later request (and every queue job in a worker) would have read a stale tenant | The subscriber resolves `AuditLogger` from the container per event |

## Out of Scope (deliberate)

- A page Content-Security-Policy for the Inertia admin. Vite's dev server
  injects inline scripts, so it needs nonce support. JSON API responses
  do get a deny-all CSP.
- Two-factor authentication and session/device management (Module 32
  later phase; Sanctum has no built-in TOTP).
- External write-once (WORM) storage for the audit trail. The hash chain
  detects tampering but does not prevent a database superuser from
  rewriting the whole chain consistently. Periodically publishing chain
  heads to a separate store is the next step.
- The breached-password (`uncompromised()`) check runs in production only,
  because it calls an external API.
