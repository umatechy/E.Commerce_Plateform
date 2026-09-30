# Phase B25 — Security Review (Storefront Customer Accounts)

All items below were verified by executed tests on MySQL 8, where a test
is named (`tests/Feature/CustomerAccount/`), and by the end-to-end run
in headless Chromium.

| # | Threat | Control | Status |
|---|---|---|---|
| 1 | Session token theft through injected script | Token only in an HttpOnly cookie, never in a response body or JS storage | Tested (no token in body; `document.cookie` cannot see it end to end) |
| 2 | CSRF with the session cookie | Cookie honoured only with `X-Storefront-Request: 1`, which a cross-site request cannot add without CORS approval; SameSite=Lax as a second layer | Tested (cookie without header → 401) |
| 3 | One store's session used on another store | Per-store cookie name on the shared host; a customer token is valid only for its own store (B24 `store_mismatch`) | Tested |
| 4 | Long-lived stolen sessions | Session tokens expire after 30 days; sign-out revokes the token; a password change or reset revokes other or all sessions | Tested |
| 5 | Account enumeration via password reset | Identical 202 response for any email; mail only for registered, non-erased accounts of this store | Tested |
| 6 | Reset-token guessing / reuse | 64 random characters, only the SHA-256 is stored, single use, 60-minute expiry, older tokens replaced, not valid in another store or for another email | Tested |
| 7 | Inbox flooding via reset | Per-IP route limit plus one mail per account per minute | Tested |
| 8 | Open redirect after login | `redirect` accepted only as a path inside the same storefront | Tested (external, `//`, other store and `javascript:` values dropped) |
| 9 | IDOR on addresses and orders | Every query pinned to the signed-in customer under the tenant scope; another customer's or store's address or order → 404 | Tested |
| 10 | Account takeover through email change | The current password is required; the new email must be free among this store's registered accounts; verification is cleared | Tested |
| 11 | Weak passwords | The platform password policy (min 10 with letters; breached-password check in production) applies to register, change and reset | Tested |
| 12 | Brute force | Login 10/min per IP plus `LoginCustomerRequest`'s per-email lockout; email/password changes 6/min. Nested throttles now have separate keys (a B25 fix) | Tested |
| 13 | Personal data in cached or server-rendered HTML | Account pages render only the shell; data is loaded per request from the APIs | Reviewed |
| 14 | Audit | Registration, sign-in/out, password change/reset request/reset, email change and consent changes are recorded in the store's audit chain | Tested |

## Residual Risks

- The reset link is part of the notification body stored in
  `notification_messages`, so anyone who can read that table could use
  a link within its 60 minutes. The alternative (not storing message
  bodies) is a Module 21 change.
- Customer email addresses are not verified at sign-up; a store that
  needs verified contacts must add a verification step.
- A merchant signed in to the admin on the same host is treated as that
  staff user by Sanctum's stateful guard, so their browser cannot also
  act as a customer on `/shop` pages of the platform host. Testing a
  store as a customer needs a separate browser profile.
