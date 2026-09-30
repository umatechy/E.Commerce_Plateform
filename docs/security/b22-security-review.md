# Phase B22 — Security Review (Module 32)

All items below were verified by executed tests on MySQL 8, where a test
is named (`tests/Feature/Compliance/`).

## Audit Trail

| # | Threat | Control | Status |
|---|---|---|---|
| 1 | Forged actor, store or surface in an entry | Derived only from the authenticated principal, `ApiKeyContext` and `TenantContext`; callers cannot pass them (except an explicit actor at login, before the request user exists) | Tested (`test_a_staff_action_records_actor_surface_store_and_request_details`, impersonation test) |
| 2 | Silent edit of an entry | Model update/delete throw. A direct SQL edit breaks the SHA-256 chain, and `audit:verify` exits non-zero | Tested (edited, deleted-middle, truncated-tail cases) |
| 3 | Audit entry for an action that rolled back | The write joins the caller's transaction | Reviewed (ADR-004) |
| 4 | Secrets in the audit store | Recursive key redaction; values capped at 1000 chars; the failed-login entry keeps the email, never the password | Tested (`test_secrets_are_redacted_and_never_stored`, staff login test) |
| 5 | Personal data copied into the audit store | Export/erase entries record counts, reason and subject id, never the exported data or the erased email | Tested |
| 6 | Cross-tenant read of the trail | `AuditLog` has no global scope by design; the store listing filters by the resolved `TenantContext` store explicitly, and there is no store parameter | Tested (`test_the_owner_lists_only_their_own_stores_entries_newest_first`) |
| 7 | Filter injection / wildcard abuse | Whitelisted filters, enum-validated `actor_type`, `per_page` ≤ 100; `%`, `_`, `\` escaped in the prefix match | Tested (`action=%` returns nothing) |
| 8 | Concurrent appends corrupting the chain | Per-chain head row lock; unique `(chain_key, sequence)` as a backstop | Reviewed |
| 9 | Stale tenant in long-lived processes | The auth-event subscriber resolves `AuditLogger` per event, never at boot (bug found and fixed in B22) | Tested (Super Admin platform routing suite) |
| 10 | Unbounded growth | `audit:prune` daily with verifiable anchors | Tested |

## Data-Subject Requests

| # | Threat | Control | Status |
|---|---|---|---|
| 11 | Erasing another store's customer | `{customer}` bound under the tenant scope → 404 | Tested |
| 12 | Accidental erasure | `privacy.manage`/Owner only, `reason` required, `confirm_email` must match, second attempt 409 | Tested |
| 13 | Erasure breaking fulfilment or financial records | Refused (409) while any order is open; orders anonymized, never deleted; totals and links kept | Tested |
| 14 | Erased account still usable | Password removed, tokens revoked, email replaced by an undeliverable placeholder | Tested (`test_an_erased_customer_can_no_longer_sign_in`) |
| 15 | Customer reading another customer's data | The self-export reads only the token's own customer; staff routes answer a customer token with 401 | Tested |

## HTTP Hardening

| # | Item | Control | Status |
|---|---|---|---|
| 16 | MIME sniffing, clickjacking, referrer leaks | `nosniff`, `X-Frame-Options: DENY`, `strict-origin-when-cross-origin`, restrictive `Permissions-Policy`, `COOP: same-origin` on every response, errors included | Tested |
| 17 | API responses rendered as documents | `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'` on `api/*` | Tested |
| 18 | Downgrade to HTTP | HSTS (1 year, subdomains) on HTTPS responses only | Tested |
| 19 | Weak passwords | `Password::defaults()` = min 10 chars with letters; plus breached-password check in production | Tested |

## Residual Risks

- A database superuser can rewrite an entire chain consistently. The
  chain detects partial tampering, not a full rewrite. Publishing chain
  heads to separate write-once storage is the recommended follow-up.
- No page CSP on the Inertia admin yet (needs nonce support for Vite).
- No two-factor authentication.
- Guest checkouts create customer rows without an account. They can be
  erased by staff, but have no self-service export (they have no token).
