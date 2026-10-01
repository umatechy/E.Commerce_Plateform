============================================================
PHASE B29 CHECKPOINT
============================================================

Phase:
Development Phase B

Module:
B29 — Security baseline (gap G2; Module 32 §8, §9.10, §48, §60–66;
Module 30 §4–6; Module 02 §13)

Implementation Summary:
Five controls the SRS requires were missing. They now exist.

1. Two-step sign-in (MFA) for staff accounts
- A user turns it on from the new Security page: password, scan a QR
  code with an authenticator app, enter a code.
- Ten recovery codes are shown once. Each works once.
- Sign-in then has two steps. The password alone gives no session.
- A code cannot be used twice. Five wrong codes pause the account's
  second step for 15 minutes.
- Turning it off, or getting new recovery codes, needs the password and
  a current code.
- Enrollment, removal, recovery-code use and wrong codes are audited.

2. Stronger authentication for platform staff
- Every Super Admin route refuses platform staff who have no MFA, and
  refuses a session that did not pass MFA.

3. Step-up authentication
- Sensitive actions need a proof of identity from the last 15 minutes:
  the sign-in itself, or POST /api/v1/auth/step-up (password, plus a
  code when MFA is on).
- Guarded: impersonation, restore request and authorization, billing
  payments/void/due date, prices, packages, platform settings, user
  deactivation/reactivation, developer application suspension.
- A refused impersonation is stopped before it starts and leaves no
  "impersonation started" audit entry.

4. Request IDs
- Every response has an X-Request-Id header. The same ID is on the
  request's log lines, on the jobs it queues and on the audit entries it
  writes (new column, part of the entry's hash).
- The audit log can be filtered by request ID.

5. Dependency scanning and the incident runbook
- CI has a `security` job: `composer audit` and `npm audit` for shipped
  packages fail the build; build tooling is reported.
- CI now also runs on pushes to `v1.1`.
- docs/security/incident-response-runbook.md.

New Components Implemented:
App\Domain\Identity — MfaService, MfaRecoveryCode, MfaController,
SecuritySession, InvalidMfaCodeException.
App\Http\Middleware — AssignRequestId, EnsurePrivilegedMfa, RequireStepUp.
config/security.php.
Dependencies: pragmarx/google2fa (PHP), qrcode (JS).

API:
- POST /api/v1/auth/login → 202 {mfa_required: true} for an MFA account
- POST /api/v1/auth/login/mfa
- GET/DELETE /api/v1/auth/mfa, POST /auth/mfa/setup, /confirm,
  /recovery-codes
- POST /api/v1/auth/step-up

Frontend:
- Sign-in page: code step
- Security page (/security) and its navigation entry

Database:
- users: mfa_secret (encrypted), mfa_confirmed_at, mfa_last_used_step
- user_mfa_recovery_codes (new)
- audit_logs: request_id

Tests:
MfaTest (10), StepUpTest (6), RequestIdTest (3). The enrollment and
two-step sign-in were also run in a browser against the local server.
Full suite on 2026-09-30: 921 passed. PHPStan: no errors. Vitest: 41
passed. Frontend build and lint pass.

Requirements closed:
AUTH-006, AUTH-007, AUTH-012, SA-004, API-011, SEC-013, SEC-015.
SEC-014 is partly closed: the dependency gate exists; other security
gates (secret scanning, static security analysis) do not.

Owner decisions of 2026-10-01 (built in the following commit):
1. Runbook roles are fixed (Incident Commander, Technical/Infrastructure
   Lead, Security Lead, Backup/Recovery Lead, Communications/Notification
   Lead). People and contacts stay TBD until provided.
2. A lost authenticator plus lost recovery codes is recovered only by a
   controlled server-side procedure: the `mfa:emergency-reset` console
   command. No screen, no endpoint. High-severity audit entry.
3. Store Owners must have MFA. An owner without it reaches only the
   enrollment flow. Store staff stay optional. Accounts with MFA get no
   remember-me cookie.

Still needed from the owner:
- The people and contacts for the runbook roles.
- Existing owners should be told before this is deployed: they must
  enrol at their next sign-in.

Open findings (docs/security/b29-security-baseline.md):
- The older tests run with MFA enforcement and step-up switched off.
- Build tooling (Vite, Vitest, esbuild) has known advisories; none
  ships. The fix is a breaking toolchain upgrade.
- No screen asks for step-up yet (the guarded actions are API-only).
- No alerts on security events (G4).

Next:
G4 (scheduled backups, restore rehearsal, alerts).
