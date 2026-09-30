# Phase B26 — Security Review (Support, Module 34)

All items below were verified by executed tests on MySQL 8, where a test
is named (`tests/Feature/Support/`), and by the end-to-end run in
headless Chromium.

| # | Threat | Control | Status |
|---|---|---|---|
| 1 | A shopper reading another shopper's ticket (IDOR) | Every customer query is pinned to the signed-in customer, the store desk and the tenant scope; another customer's ticket → 404 on read and reply | Tested |
| 2 | Guessing or stealing a guest's link | 48 random characters; only the SHA-256 is stored, compared with `hash_equals`; a wrong or missing token → 404, same as a missing ticket | Tested |
| 3 | The guest token leaking through URLs | The email link carries it in the `#fragment` (never sent by browsers); the page sends it in `X-Support-Token`; it is never written to an outbox payload, page HTML or the server's request log | Tested (email link, page shell); end to end (not in the server log) |
| 4 | One store seeing another store's tickets | Tenant scope on every query; the store inbox holds only its own store-desk tickets; the platform desk is visible only to platform staff | Tested |
| 5 | Shoppers seeing internal team notes or staff details | The requester's view drops internal notes, assignee, SLA and priority, and names agents by first name; internal notes send no email | Tested |
| 6 | Staff overreach | `support.view` / `support.reply` / `support.manage` are checked server-side on every call; assigning is limited to active team members who may answer support (or platform staff on the platform desk) | Tested |
| 7 | Stored XSS through message text | Messages are plain text: React escapes them on every page (`whitespace-pre-wrap`, never HTML); email variables are escaped by NotificationService | Tested (Vitest renders `<img onerror>` as text; email escaping test) |
| 8 | Contact-form spam and flooding | A honeypot field (`website`, must be empty); per-IP limits (contact 3/min, guest replies 10/min, customer opens 10/min, replies 30/min) with separate limiter keys; at most 20 open requests per requester | Tested (honeypot, cap) |
| 9 | Linking someone else's order | A customer can link only their own orders; a guest's order number is linked only when its email matches | Tested |
| 10 | CSRF on the storefront | The same rules as B25: the customer session cookie counts only with `X-Storefront-Request`; admin writes carry the XSRF token (`adminFetch`) | Tested (B25); end to end |
| 11 | Open redirect after staff sign-in | `intended` is taken from the server session and accepted only as a path on the same host | Tested (a foreign URL → no redirect) |
| 12 | Unauthorised platform inbox access | `/super-admin/support` page and API need a platform role (`can:super-admin.platform`); API calls run in platform context and are audited | Tested (store owner → 403) |
| 13 | Audit | Status, priority, topic and assignee changes are recorded in the store's audit chain with before/after values | Tested |

## Residual Risks

- Messages have no attachments yet. When they are added, uploads need
  type checks and malware scanning.
- A guest's link cannot be revoked or rotated. Anyone who has the email
  can follow the request until it is closed.
- Guest contact requests are not verified by email. A visitor can open a
  request using someone else's address; that person then receives the
  acknowledgement email. This is the same exposure as any contact form,
  limited by the per-IP rate limits.
- The message body is stored in `notification_messages`, as with other
  emails (see the B25 review).
