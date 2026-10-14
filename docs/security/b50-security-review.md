# B50 security review — WhatsApp and SMS

| Area | Risk | Control | Test |
|---|---|---|---|
| Provider credentials (access token, app secret, auth token) | Leak through API, logs, DB | encrypted at rest, hidden from serialisation, masked hints / "set", audit with field names only, shared `CredentialForm` rules; step-up to save or disconnect; `messaging.providers` | secrets not in response nor DB column; manager cannot connect |
| Webhook details | Webhook address / verify token shown to staff who cannot configure | only returned to people with `messaging.providers` (platform: platform staff) | manager sees `webhook_url: null` |
| Forged delivery reports / STOP | False "delivered", silencing a customer | account found by a 48-character token, then signature checked with that account's own secret (Meta `X-Hub-Signature-256`, Twilio `X-Twilio-Signature`, Test HMAC); unknown token 404, bad signature 403 | forged signature 403; Meta and Twilio signature tests |
| Replays | Same report applied twice | `messaging_webhook_events` unique per account and event key | duplicate ignored |
| Cross-store reports | One store's account changing another store's messages | status updates only match delivery attempts of the account's own store (and platform account: platform messages) | foreign account with a real message id → ignored |
| Consent (Meta policy; Module 21 §9) | WhatsApp without the customer's yes | order or customer opt-in required; checkbox unticked by default; STOP suppresses and turns opt-in off; audit on profile change | no-yes test; STOP/START test |
| Marketing by SMS/WhatsApp | Unwanted messages | only transactional order updates are wired; WhatsApp only through approved templates (no free text) | template-missing test |
| Cost runaway | A bug or loop spending the store's balance | daily limit per store (default 500, 1–100000); one message per order/update/channel; unknown outcomes never retried | daily-limit test |
| Duplicate sends | Retry after an unanswered call sends twice | "no answer" fails without retry; idempotency keys | silence test |
| Personal data | Phone numbers in logs and audit | numbers masked in audit (`+92300•••4567`); message bodies not listed in the admin | — |
| Unverified provider live | Twilio sending real SMS before checks | `messaging.live_providers` gate, Super Admin only, reason audited; closing switches live accounts off; production refused until opened | production refused test; open/close test |
| Test messenger in production | A no-op channel pretending to send | only when `config('messaging.simulator')` (default off outside local/testing) | — |
| Settings bypass | Changing messaging keys through the generic settings API | `messaging.*` refused (`managed_on_messages_page`); platform `messaging.live_providers` own-page | generic settings test |
| Tenant isolation | Another store's accounts or messages | tenant-scoped models; platform rows only reachable from Super Admin routes | isolation test |

Known, not new: every platform staff member has the same platform rights
(since B16), so the platform messaging account is not limited to a
Super Admin role. Recorded in b50-whatsapp-sms.md §7.

No new dependency. No real provider was called; no credential is in Git.
