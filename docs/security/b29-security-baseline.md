# B29 — Security Baseline: design and review

Date: 2026-09-30 · Gap G2 · Sources: Module 32 §8, §9.10, §48, §60–66;
Module 30 §4–6; Module 02 §13; SRS AUTH-006, AUTH-007, AUTH-012, SA-004,
API-011, SEC-013, SEC-014, SEC-015.

## What was built

| Control | Requirement | Where |
|---|---|---|
| MFA with an authenticator app (TOTP) | AUTH-006, M32 §8.1–8.2 | `MfaService`, `MfaController`, `/security` page |
| Single-use recovery codes | AUTH-007, M32 §8.3–8.4 | `user_mfa_recovery_codes` |
| MFA mandatory for platform staff | AUTH-012, M32 §65.2–65.3 | `EnsurePrivilegedMfa` on both Super Admin route groups |
| Step-up for sensitive actions | SA-004, M30 §6, M32 §65.5 | `RequireStepUp`, `POST /api/v1/auth/step-up` |
| Request / correlation IDs | API-011, M32 §63.3 | `AssignRequestId`, `audit_logs.request_id` |
| Dependency scan in CI | SEC-013, SEC-014, M32 §48 | `security` job in `.github/workflows/ci.yml` |
| Incident response procedure | SEC-015, M32 §60–64 | `incident-response-runbook.md` |

## Design decisions

**TOTP through `pragmarx/google2fa`.** The algorithm is RFC 6238 and
works with every common authenticator app. A maintained library was
preferred to writing the code generation by hand. The QR code is drawn in the
browser (`qrcode` package), so the secret is sent to no third party.

**A password alone never signs in an MFA account.** `LoginRequest` checks the
password with `validate()`, which does not sign in. The session only records
who owes a code and until when (10 minutes). There is no session, no
remember-me cookie and no "login succeeded" audit entry until the code is
accepted.

**A code works once.** The 30-second step of the last accepted code is
stored; an equal or older code is refused. One step either side of now is
accepted for clock drift.

**Secrets at rest.** The TOTP secret is encrypted with the application key
(model cast) and is hidden from every API response. Recovery codes are stored
as HMAC-SHA256 under the application key; they are random (about 51 bits
each), so a slow password hash is not needed, and the key keeps a stolen
database from being searched offline.

**Wrong codes are limited per account**, not per IP address: 5 wrong codes,
then a 15-minute pause that a right code does not shorten. The counter covers
TOTP and recovery codes together.

**Turning MFA off** needs the password and a current code (M32 §8.6).

**MFA state lives in the server-side session.** A request without a session
(a bearer token) cannot satisfy the MFA or step-up check, so it cannot reach
a Super Admin route or a step-up action while those controls are on.

**Step-up** is a timestamp in the session, set at sign-in and by
`POST /auth/step-up` (password, plus a code when MFA is on). It is valid for
15 minutes. The middleware runs before the Super Admin context switch, so a
refused impersonation leaves no "impersonation started" entry.

Step-up actions (asserted by `StepUpTest`):
impersonate a store; authorize a restore; request a restore; record a
payment, void an invoice, extend a due date; create or change a price;
create or change a package; change a platform setting; deactivate or
reactivate a user; suspend a developer application.

**Request IDs.** An inbound `X-Request-Id` is kept only if it is 8–64
characters of letters, digits, `.`, `_` and `-`. Anything else is replaced by
a UUID, so the header cannot inject content into logs. The ID is in Laravel's
log context (and therefore on queued jobs) and in a new `audit_logs` column.
The column is part of an entry's hash whenever it is present, so entries
written before this phase still verify.

**The numbers are proposed defaults** (`config/security.php`): 10 recovery
codes, 10-minute challenge, 5 attempts, 15-minute lockout, 15-minute step-up.
The specifications require the controls and set no durations.

## Review findings

| # | Finding | Severity | Status |
|---|---|---|---|
| 1 | **The test suite runs with MFA enforcement and step-up switched off** (`phpunit.xml`). The older tests reach Super Admin routes through `actingAs()`, which never signs in and so has no MFA or step-up state. The new tests switch both on. A route added later without `step_up` is caught only by the route list in `StepUpTest`. | Medium | Open. Convert the older tests to a signed-in helper. |
| 2 | **Build tooling has known advisories**: `npm audit` reports 5 (1 critical, 1 high, 3 moderate) in Vite, Vitest and esbuild. None ships to production (`npm audit --omit=dev` is clean). Fixing them needs Vite 5 → 8 and Vitest 2 → 5, a breaking upgrade. CI reports them without failing. | Medium | Open. Upgrade the toolchain, then make that CI step blocking. |
| 3 | **No UI asks for step-up.** The guarded actions are API-only today. A caller gets `403 {"code":"step_up_required"}` and must call `POST /auth/step-up`. | Low | Open. Add the prompt with the admin screens (G6). |
| 4 | **Platform staff who lose their authenticator and their recovery codes** can only be restored from the server (runbook, "Break-glass access"). There is no audited in-app reset by another Super Admin. | Medium | Open. Needs a decision on who may reset whom. |
| 5 | **Shorter sessions for privileged users** (M32 §65.4) and session/device listing (AUTH-005) are not built. | Low | Open. |
| 6 | **No alert on security events** (many failed logins, MFA disabled on a platform account). Owner decision §4 requires email alerts. | Medium | Open (G4). |
| 7 | Sign-in previously re-hashed a password on login through `attempt()`. The new path calls `rehashPasswordIfRequired()` itself, so this is unchanged. | — | Verified |
| 8 | Passkeys / WebAuthn, SMS and email one-time codes (M02 §13) are not built. TOTP is the only method. | Low | Open, by design for this phase. |
| 9 | The local development database gained test accounts while checking this phase in a browser (`check…`, `mfa…`, `dbg…@example.com`). Local only. | — | Noted |

## Verification

- `tests/Feature/Auth/MfaTest.php` — 10 tests: enrollment, two-step sign-in,
  replay, recovery codes, lockout, challenge timeout, turning off, new
  recovery codes, platform staff enforcement, store staff unaffected.
- `tests/Feature/Auth/StepUpTest.php` — 6 tests, including the list of
  guarded routes and that a refused impersonation leaves no audit entry.
- `tests/Feature/Infrastructure/RequestIdTest.php` — 3 tests, including
  that a changed or removed request ID breaks the audit chain.
- The enrollment and two-step sign-in were also run in a real browser
  against the local server (QR code shown, wrong code refused, 10 recovery
  codes shown once, sign-in asks for the code, `/auth/me` is 401 until then).
- `composer audit` and `npm audit --omit=dev`: no advisories on 2026-09-30.
