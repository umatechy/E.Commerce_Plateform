============================================================
PHASE B52 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B52 — Security and operations:
- Module 32 §7, §9, §58–64, §65, §67, §85.
- Module 30 §3–5, §58–61, §87–88, §128.
- SRS AUTH-005.
- Open findings 2, 5, 6 and 10 of docs/security/b29-security-baseline.md.

Owner request (2026-10-14): "ab b52 start kro".

Starting point:
- v1.1 at af9cf69 (B51, CI green).
- Every platform staff member had every platform right (noted in B50 and
  B51).
- No session list or revocation, and no shorter sessions for privileged
  users.
- Staff could neither change nor recover their password.
- No alerts on security events, no incident records, and no emergency
  switches in one place.
- npm audit: 14 advisories (2 critical) in the build tooling.

Implementation Summary:
1. Platform roles: super, platform, operations, support, billing and
   security admin, and read-only auditor.
   - Every Super Admin route is mapped to a capability
     (EnsurePlatformCapability). Unmapped changes are refused, and a test
     guards this.
   - Legacy and unknown roles fail closed.
   - Platform access is granted on the server only (platform:staff).
   - A security admin changes roles on the Security page. Nobody can change
     their own role, and the last super admin is protected.
2. Sessions (user_sessions, TrackUserSession):
   - listed per device; one or all other sessions can be ended;
   - ended on a password change or reset, an emergency MFA reset,
     deactivation, or removal of rights;
   - platform staff sessions end after 30 minutes idle and 12 hours in all.
3. Staff passwords:
   - change on the Security page (current password required, other devices
     signed out, email notice);
   - "forgot password": no account enumeration; hashed, single-use,
     60-minute token in the URL fragment; the reset signs out everywhere;
     two-step sign-in is kept.
4. Security monitoring (security_events) with alert rules:
   - failed sign-ins on staff accounts and from one address;
   - MFA turned off or reset;
   - role changes;
   - large exports;
   - store deletion requests;
   - authorised restores;
   - emergency switches.
   Alerts go through the existing critical alert channel (email, and
   WhatsApp when set up), at most once an hour each. Events are pruned after
   180 days.
5. Super Admin → Security:
   - overview (sign-ins, alerts, MFA coverage, sessions, backups,
     incidents);
   - emergency controls: maintenance mode, sign-ups, and the new Developer
     API kill switch — each with a reason, step-up, audit and alert, and
     moved off Platform settings;
   - platform staff;
   - security events;
   - incidents: SEV0–4, status lifecycle, a timeline that is only added
     to, closing needs a resolution.
6. Toolchain: Vite 8, Vitest 5, @vitejs/plugin-react 6,
   laravel-vite-plugin 3.
   - Advisories went from 14 (2 critical) to 7 (0 critical).
   - CI now fails on a critical advisory in tooling.
7. The runbook has a "Security alert" procedure. Findings 2, 5, 6 and 10 of
   b29 are updated.

Docs: docs/architecture/b52-security-operations.md, docs/security/b52-security-review.md,
docs/security/incident-response-runbook.md, gap matrix (Modules 30 and 32,
AUTH-005, HEALTH-005, SA-006, SEC-013).

Found and fixed on the way:
- Platform emails failed with "tenant context missing". TenantContext is
  scoped, and a service holding it from its constructor saw a stale
  instance after a synchronous job ran. New code now resolves it when it
  is used.
- 13 test files and the backup test helper used "support_agent" for
  platform-wide actions. Those classes now use a role that has the right.
- Accessible names: jsdom 30 differs from browsers (spaces before inline
  text), so jsdom stays at 25. It had no advisory.
- A test mocked a constructor with an arrow function, which Vitest 4+ does
  not allow. It now uses a function.

Conflicts and decisions to confirm (design defaults, configurable):
- Staff sessions: 30 minutes idle and 12 hours absolute.
- Large export alert: 5,000 rows.
- Security events kept 180 days.
- The 7 advisories left are in Tailwind CSS 3's file watching (build time
  only). They are fixed only by Tailwind 4, a migration of the whole UI.
  Owner's choice when.

Tests:
- New: SecurityOperationsTest (8, with real sign-ins and session cookies)
  and security.test.tsx (6).
- StepUpTest gained 3 routes. StoreDataMap classifies security_events.
- PHP suite: 1243 passed (8044 assertions).
- PHPStan: no errors.
- Vitest: 248 passed (39 files).
- ESLint and TypeScript clean; production build OK (Vite 8).

Browser check (real Chromium, local server, MFA and step-up on): 8 of 8
passed, no console errors.
1. Forgot password from the sign-in page; the link from the mail log has
   the token in its #fragment, and the token is not stored readable; new
   password; signed in.
2. The Security page lists two devices (Chrome on Windows, Safari on iOS);
   "Sign out all other devices"; the iPhone context is sent to
   /login?ended=revoked with a clear message.
3. Password change signs the other device out (ended=password_changed).
4. Super Admin → Security overview; the Developer API turned off (reason,
   step-up) and back on; alerts recorded.
5. The role of a local staff account changed (operations → support,
   step-up); an alert was recorded.
6. Incident INC-2026-0002: opened, resolved with a resolution, closed; the
   timeline was kept.
7. Five failed sign-ins on a staff account: exactly one alert; the events
   tab lists them.
8. 375 px: the Super Admin Security page and the account Security page fit.

Local data note:
- platform.local@example.com was given super_admin, and b52.ops@example.com
  support_admin, through platform:staff (local accounts only).
- b52.user@example.com was created for the password checks.

Not built (b52-security-operations.md §7):
- passkeys;
- staff IP or device restrictions;
- shorter owner sessions;
- a store-level security dashboard;
- on-screen alert routing;
- the Tailwind 4 migration.

Owner inputs needed (at the end, as agreed):
- the incident runbook contacts (still open since B29);
- who should hold super admin.

CI: run on b6a0401 — success (security, backend, frontend), verified 2026-10-14.

Next (on the owner's word): B53 — API quality.
