# B52 — Security and operations

Sources:
- Module 32: §7 passwords, §9 sessions, §58 monitoring, §59 alerting, §60–64 incidents, §65 Super Admin security, §67 support staff, §85 security dashboard.
- Module 30: §3 role model, §4–5 privileged account and session, §58 security operations, §59 incidents, §60 emergency controls, §61 kill switch, §87–88 incident management, §128 platform lockdown.
- SRS AUTH-005.
- docs/security/b29-security-baseline.md, open findings 2, 5, 6 and 10.

## 1. Platform roles (least privilege)

Before B52, every Umar Techy staff member could do everything on the platform (noted in B50 and B51). Now `users.platform_role` decides what each person may do.

| Role | Can change |
|---|---|
| Super admin | Everything, including staff roles and permanent deletion |
| Platform admin | Stores, impersonation, catalog, billing, providers, settings, backups, support, users, developer |
| Operations admin | Stores, impersonation, backups, support, developer |
| Support admin | Opening a store to help, support |
| Billing admin | Billing, including stores' packages and subscriptions |
| Security admin | Staff roles, sessions, incidents, emergency controls, users, developer |
| Read-only auditor | Nothing (can look at everything) |

How it is enforced:
- Every role can read the Super Admin pages.
- `EnsurePlatformCapability` maps each Super Admin route to a capability (`PlatformRoles::ROUTES`). A route that changes something and is not mapped is refused, and a test fails for any such route.
- Roles from before B52 still work: `support_agent` is read as support admin. Any unknown value gets read-only rights (fail closed).
- Giving an account platform access at all happens only on the server, with `php artisan platform:staff` (operator, reason, the email typed again). A stolen admin session therefore cannot create new staff.
- On the Security page a security admin can change a staff member's role or remove their access. Nobody can change their own role, and the last active super admin can never be demoted, removed or deactivated.
- Every change is audited and raises an alert. When the new role has fewer rights, the person's sessions end.
- **Owner decision 16 (2026-10-14):** the super admins are umartechy01@gmail.com and callmodeai@gmail.com. On a new server, after each account exists:
  `php artisan platform:staff <email> super_admin --operator="<name>" --reason="Owner decision 16"`.
  Both set up two-step sign-in at their first sign-in. Everyone else gets a narrower role.
- The screens hide buttons the role cannot use (Store life card, Store page). The server checks every call anyway.

## 2. Sessions (SRS AUTH-005; Module 32 §9; Module 30 §5)

- `user_sessions` records each signed-in staff session: device, address, when it signed in, when it was last used, and whether two-step sign-in was used. Only the SHA-256 of the session id is stored.
- `TrackUserSession` runs on web pages and the staff API. A session that was ended elsewhere is signed out on its next request: the API answers 401 `session_ended`, and pages redirect to `/login?ended=…`, which shows the reason.
- **Security page:** the person sees "Where you're signed in" and can sign out one device or all the others.
- Platform staff sessions end after 30 minutes without activity and after 12 hours at the latest. These are configuration defaults (`PRIVILEGED_SESSION_IDLE_MINUTES`, `PRIVILEGED_SESSION_ABSOLUTE_HOURS`), not owner decisions.
- All sessions end, and remembered sign-ins are revoked, on:
  - a password change (the current session is kept);
  - a password reset;
  - an emergency MFA reset (b29 finding 10);
  - deactivating the account;
  - removing platform rights.

## 3. Passwords (Module 32 §7)

Until B52, staff accounts could neither change nor recover their password.

- **Change password** (Security page): needs the current password and must differ from it. Other devices are signed out, and the person is emailed.
- **Forgot password** (`/forgot-password`):
  - The answer is the same whether or not the account exists.
  - The token is 64 characters, stored only as SHA-256, valid 60 minutes and usable once.
  - At most one email a minute per account.
  - The link carries the token in the URL `#fragment`, so it never reaches a server log.
  - The email text holds the link only in the encrypted `sealed_body`.
- **Reset** (`/reset-password`): sets the new password and signs out everywhere. Two-step sign-in is untouched, so the code is still needed to sign in.

## 4. Security monitoring and alerts (Module 32 §58–59; b29 finding 6)

`security_events` records:
- failed sign-ins and lockouts;
- wrong two-step codes and failed step-up;
- MFA turned on, turned off or reset;
- password changes and resets;
- sessions ended or timed out;
- role changes and account deactivations;
- data exports;
- store deletion requests, authorised restores, emergency switches and incidents.

Events are kept for 180 days (`security:prune`, daily).

Alert rules, each sent at most once an hour for the same account or address:

| Rule | Severity |
|---|---|
| 5 or more failed sign-ins in 15 minutes on a platform staff account | high |
| 20 or more failed sign-ins in 15 minutes from one address | medium |
| Two-step sign-in turned off on a platform staff account | high |
| Emergency MFA reset used | high |
| Platform role changed | high |
| Large export (`SECURITY_MASS_EXPORT_ROWS`, default 5,000 rows) | medium |
| Permanent store deletion requested | high |
| Production restore authorised | high |
| Emergency control changed | high |

Alerts go through the existing critical alert channel (outbox event `security.alert` → `CriticalAlertNotifier`): email to platform staff, and WhatsApp when set up (B50). Each alert has a severity, the time, the source, the account or store, and what to do (§59).

## 5. Super Admin → Security (Module 32 §85; Module 30 §58)

- **Overview:**
  - failed sign-ins (all, and on staff accounts), lockouts, alerts in the last 7 days;
  - open incidents by severity;
  - two-step coverage of platform staff and store owners;
  - active staff sessions and the session limits;
  - last verified backup and last restore rehearsal;
  - recent alerts, with what to do.
- **Emergency controls** (§60–61, §128). Each is changed with a reason and step-up, is audited, raises an alert, and keeps its setting history:

  | Control | Normal | Emergency |
  |---|---|---|
  | Maintenance mode | off | on: storefronts show maintenance |
  | New store sign-ups | on | off |
  | Developer API (new kill switch) | on | off: every API key gets 503 |

  These three are no longer changed on Platform settings (`managed_on_security_page`). Narrower controls stay where they already live: store suspension, provider live access, developer application suspension, account deactivation.
- **Platform staff:** role, two-step status, last use, open sessions; change a role, sign someone out everywhere; and what each role may do.
- **Security events:** filter by event and severity, search by email, address or store.
- **Incidents** (Module 30 §59, §87–88; Module 32 §60–64):
  - Fields: SEV0–SEV4 with written definitions, component, scope, impact, owner.
  - Status: detected → triaged → investigating → containing → mitigating → resolved → closed. Steps can be skipped, and a resolved incident can be reopened.
  - Closing needs a resolution, and a closed incident is final.
  - The timeline is only ever added to.
  - The `INC-YYYY-NNNN` reference ties the incident to the runbook.

## 6. Toolchain (b29 finding 2)

- Upgraded Vite 5 → 8, Vitest 2 → 5, @vitejs/plugin-react 4 → 6 and laravel-vite-plugin 1 → 3.
- jsdom stays at 25. Version 30 computes accessible names differently from browsers (spaces before inline text). It had no advisory.
- `npm audit`: 14 advisories (2 critical) → 7 (0 critical). CI now fails on a critical advisory in build tooling.
- The 7 left (high and moderate) are all in Tailwind CSS 3's file-watching chain (braces, micromatch, postcss-selector-parser). They are fixed only by Tailwind 4, a separate migration of the whole UI. They are build-time only and do not ship.
- One test changed for Vitest 4+: a mocked constructor must be a `function`, not an arrow function.

## 7. Not built / limits

- Passkeys / WebAuthn and SMS or email codes (b29 finding 8).
- IP or device restrictions for staff (Module 30 §5, Module 32 §65.10).
- Shorter sessions for store owners. Their sessions follow the normal session lifetime and can be ended from the Security page.
- A security dashboard for store administrators (§85: "tenant-relevant metrics"). A store's own audit log already exists.
- Alert thresholds, routing and suppression windows configurable on screen (Module 30 §89). They are code and configuration today.
- Older tests still reach Super Admin routes through `actingAs()` without MFA or step-up state (b29 finding 1). The new tests use real sign-ins.
- Tailwind 4 migration (see §6).
