============================================================
PHASE B50 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B50 — WhatsApp and SMS (gap G10; SRS NOTIF-004; Module 21; Module 30 §27;
owner decisions 3 and 4)

Owner request (2026-10-14): "g b50 start kren".

Starting point:
v1.1 at e2c84f2 (B49, CI green). Notifications (B11) had templates, messages,
delivery attempts, suppressions, preferences and a working email channel.
SMS and WhatsApp were stubs that recorded every message as failed
(`channel_not_configured`). Critical alerts (B30) had a WhatsApp switch with
no provider behind it.

Implementation Summary:
1. Messaging adapters behind one contract: Meta WhatsApp Cloud API
   (templates, template status, X-Hub-Signature-256, delivery and inbound
   webhooks), Twilio SMS (form API, StatusCallback, request signature), Test
   messenger for local/test systems. E.164 phone numbers, Pakistan default.
2. MessagingChannel in the existing B11 pipeline: account, number,
   suppression, daily limit and approved template checked at send time.
   Temporary failures are retried; permanent failures and unanswered calls
   are not.
3. MessagingNotifier: order confirmed, shipped, out for delivery,
   delivered, delivery not possible, cancelled and payment received, by
   SMS and/or WhatsApp per the store's choice. WhatsApp only with the
   customer's yes. One message per order, update and channel.
4. Webhooks: account found by token, signature checked with its own
   secret, each event applied once. Delivered / read / failed are recorded.
   STOP and START manage suppressions and the WhatsApp yes.
5. Consent: checkout box (unticked, shown only when the store sends
   WhatsApp updates), account Profile box, order and customer flags.
6. Platform: Umar Techy's own accounts and the approved `critical_alert`
   template. Critical alerts now go out on WhatsApp when set up. Twilio
   is gated until opened (with a reason; closing switches live accounts
   off).
7. Screens: Messages → WhatsApp & SMS (channel cards with test message and
   webhook details, order updates by channel with wording and daily limit,
   WhatsApp templates with the exact body to submit); Super Admin → WhatsApp
   & SMS (24 h counts, alert readiness checklist, accounts, alert template,
   SMS providers' live access). Urdu storefront texts.

Docs: docs/architecture/b50-whatsapp-sms.md, docs/security/b50-security-review.md,
gap matrix (G10, Module 21, NOTIF-004).

Conflict reported (not silently resolved):
- Twilio and Meta adapters follow the providers' published documentation
  and are pinned by faked-HTTP tests. Neither was called live: no
  credentials exist yet. Production Meta use needs the store's own approved
  WhatsApp Business account. Twilio stays sandbox-only until Umar Techy has
  checked sender ID, delivery and price for Pakistan (owner decision 3).
- No Pakistani SMS provider is coded: none was chosen or verified.
- Every platform staff member has the same platform rights (existing
  since B16), including Umar Techy's messaging accounts. Noted, not changed
  here.

Tests:
- New: MessagingTest (7), MessagingAdaptersTest (7), a CriticalAlertTest
  case for WhatsApp alerts through the platform account; StepUpTest route
  list updated.
- PHP suite: 1228 passed (7594 assertions).
- PHPStan: no errors.
- Vitest: 235 passed (37 files), including messaging.test.tsx (5).
- ESLint and TypeScript clean; production build OK.

Browser check (real Chromium, local server, MFA and step-up on, production
build, B46 Tax Store and local platform staff): 7 of 7 passed, no console
errors.
1. Test WhatsApp and Test SMS connected (step-up) and on; secrets never shown.
2. Webhook address shown and copyable; test message accepted; a bad number
   refused, with the reason.
3. Order confirmed chosen for SMS and WhatsApp; template set → Approved.
4. Guest checkout: WhatsApp box unticked, asks for a phone first; order
   ORD-000020 placed with the yes. After the outbox relay (the scheduler's
   `outbox:publish`), the SMS and the WhatsApp were both sent to
   +92300•••.
5. Sent messages list shows both.
6. Super Admin page: checklist, Twilio open dialog (cancelled), menu entry,
   375 px fit.
7. 375 px: the WhatsApp & SMS tab fits. The first run found the updates
   table at 442 px; it was fixed.

Not built (b50-whatsapp-sms.md §7): push notifications, a Pakistani SMS
adapter, marketing messages by SMS/WhatsApp, a conversation inbox, message
prices, template submission by API.

Owner inputs needed (at the end, as agreed): a WhatsApp Business account
(Meta app, phone number ID, permanent token, app secret) for Umar Techy's
alerts and the first store; the SMS provider choice and its sender-ID check
(Twilio or a Pakistani provider).

CI: pending — to be recorded after the push.

Next (on the owner's word): B51 — store lifecycle and closure.
