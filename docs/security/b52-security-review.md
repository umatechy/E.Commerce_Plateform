# B52 security review — security and operations

| Area | Risk | Control | Test |
|---|---|---|---|
| Platform staff privileges | Any staff member (or a stolen staff session) can change billing, providers, settings or delete stores | Roles with capabilities; every Super Admin route mapped, unmapped changes refused; legacy and unknown roles fail closed | route coverage test; billing/auditor/support refusals |
| Creating platform staff | A stolen admin session adds a new super admin | Platform access granted only by `platform:staff` on the server (operator, reason, typed email); screens only change roles of existing staff | command test; screen refuses non-staff (422) |
| Privilege changes | Self-promotion, losing the last super admin, silent changes | No change of one's own role; last active super admin protected (role change and deactivation); audit + high alert; sessions end when rights shrink | role tests |
| Session theft / stale sessions | A stolen or forgotten session stays valid | Sessions listed and revocable; revoked on password change, reset, MFA reset, deactivation, rights removal; staff idle (30 min) and absolute (12 h) limits; only a hash of the session id stored; a session never carries over to another account | session tests (end elsewhere, password change, idle timeout, deactivation) |
| Password recovery | Account enumeration, token leaks, reuse | Same answer for unknown emails; 64-char token stored as SHA-256; 60 min, single use, replaces older; one email a minute; token in the URL fragment (never in logs) and only in the encrypted message body; reset signs out everywhere; MFA still required | reset test |
| Password change | Change without knowing the password | Current password required (failed attempt recorded); new must differ; Password::defaults() (10+ chars, letters; breached-password check in production); email notice | session test |
| Password guessing | Undetected attacks on staff accounts | Existing per-account slow-down, plus failed sign-ins recorded and alerted (5 in 15 min on a staff account, 20 from one address), once per hour | alert test |
| Silent sensitive actions | MFA turned off, restores, deletions, exports unnoticed | Security events and alerts for each (Module 32 §59) through the existing critical alert channel (email, WhatsApp) | alert, emergency tests |
| Emergency switches | Changed casually or without trace | Security capability, reason, step-up, audit, alert, setting history; no longer editable on Platform settings | emergency test |
| Incident records | Timeline edited after the fact | Updates only appended; closed incidents final; closing needs a resolution | incident test |
| Monitoring data | Personal data kept forever | Events kept 180 days (`security:prune`); emails masked for events without an account; ended sessions 90 days | — |
| Build tooling | Known advisories in Vite/Vitest/esbuild | Upgraded (0 critical); CI fails on a critical advisory in tooling; Tailwind 3 high advisories reported until the Tailwind 4 migration (build-time only) | npm audit |

Fixed on the way:
- `TenantContext` is scoped. A service holding it from its constructor can see a stale instance after a synchronous job has run, so platform-level emails failed with "tenant context missing". New code now resolves it when it is used.

No secret added. No new runtime dependency. Toolchain upgraded (dev only).
