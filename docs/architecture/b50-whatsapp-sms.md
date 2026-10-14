# B50 — WhatsApp and SMS (gap G10)

Sources: Module 21 (Notifications & Communication) §8–10, §18–19, §23,
§29, §31–37, §40–41, §46, §48, §51–52, §54, §59; Module 30 §27; SRS
NOTIF-004; owner decision 3 (WhatsApp through Meta's WhatsApp Business Cloud
API; SMS behind an abstraction, Twilio or a Pakistani provider after
sender-ID, delivery and price checks) and decision 4 (critical alerts:
email mandatory, WhatsApp configurable).

## 1. What this phase adds

| Area | Before (B11, B30) | After B50 |
|---|---|---|
| SMS / WhatsApp channels | stubs; every message failed `channel_not_configured` | `MessagingChannel` sends through the store's (or Umar Techy's) account |
| Providers | none | Meta WhatsApp Cloud API, Twilio SMS, Test messenger (local/test only) |
| Order updates | email only | order confirmed, shipped, out for delivery, delivered, delivery not possible, cancelled, payment received — per channel, chosen by the store |
| Consent | — | WhatsApp only with the customer's yes (checkout box, account page); STOP / START replies |
| Delivery | "sent" was the last state | signed provider webhooks → delivered / read / failed |
| Critical alerts | WhatsApp recorded as failed | sent through Umar Techy's WhatsApp account with an approved template |
| Screens | — | Messages → WhatsApp & SMS; Super Admin → WhatsApp & SMS; checkout box |

## 2. Data

Migration `2029_09_01_000001_messaging_providers`:

- `messaging_accounts`: one per (store, channel); `store_id` null means
  Umar Techy's own. Credentials encrypted; masked `credential_hints`;
  `webhook_token` (48 random characters) names the account in its webhook
  address and is Meta's verify token.
- `messaging_templates`: which approved WhatsApp template carries which
  update (`critical_alert` for the platform), its language and approval
  state (Module 21 §37: tracked separately from the platform's templates).
- `messaging_webhook_events`: each provider event applied once per account.
- `notification_messages.provider_parameters`: the template values in order.
- `customers.whatsapp_opt_in(_at)`, `orders.whatsapp_opt_in`.
- Permission `messaging.providers` (Owner has every permission; granted to
  Administrator).

## 3. Flow

1. A business event already emitted by Orders, Payments or Shipping
   (`order.created`, `order.cancelled`, `payment.succeeded`,
   `shipment.status_changed`) reaches `NotificationEventRouter`, which also
   calls `MessagingNotifier`. No new event was invented.
2. `MessagingNotifier` sends only when the store chose that update for that
   channel, the channel's account is on, the order's phone number can be
   read (E.164, Pakistan as the default country), and for WhatsApp the
   customer said yes. One message per order, update and channel
   (idempotency key).
3. `MessagingChannel` checks again at send time: account on, number valid,
   not suppressed (STOP), the store's daily limit, and for WhatsApp an
   approved template. The adapter's answer maps to:
   - accepted: the message is sent, with the provider's message id;
   - temporary failure: retried by the existing B11 job;
   - permanent failure: failed at once, no retry;
   - no answer: failed **without** a retry, because the message may have
     gone out and a retry could send it twice.
4. The provider calls `/api/v1/messaging-webhooks/{token}`. The account is
   found by its token, and the signature is checked with that account's
   secret. Each event is applied once; status updates only touch messages
   of the same store. Delivery reports set delivered / read / failed.
   STOP adds a suppression and turns the customer's WhatsApp yes off;
   START removes the suppression.

## 4. Providers

| Provider | Checked against | Notes |
|---|---|---|
| Meta WhatsApp Cloud API | Meta's published Cloud API docs: `POST /{phone-number-id}/messages` with a template, `GET /{waba-id}/message_templates`, `X-Hub-Signature-256` (HMAC-SHA256 of the raw body with the app secret), `hub.challenge` verification, `statuses[]` / `messages[]` payloads. Graph version in `config/messaging.php` (`v25.0`). | Business messages outside the customer-service window must be approved templates, so free text is never sent. Each store uses its own WhatsApp Business account; not gated. |
| Twilio SMS | Twilio's published Messages API (form POST, basic auth, `StatusCallback`) and its request-signature scheme (URL + sorted fields, HMAC-SHA1, base64) | **Gated**: real messages only after the Super Admin opens Twilio, once sender ID, delivery and price for Pakistan are checked (owner decision 3). |
| Test messenger | — | Sends nothing. Only when `MESSAGING_SIMULATOR` is on (default on for local/testing). Numbers ending 0002 / 0003 / 0004 give permanent / temporary / no-answer results. |

The adapters are pinned by `MessagingAdaptersTest` with faked HTTP. A live
check with real accounts is still owed before production use (owner input).
No Pakistani SMS provider is coded: none was chosen or verified.

## 5. Screens

- **Messages → WhatsApp & SMS** (store). From top to bottom:
  1. A card per channel with its provider, state, last answer or last
     problem, the webhook address (and verify token) to copy, a test
     message, and buttons to turn it on or off, edit, disconnect or switch
     provider.
  2. Order updates: a table of updates by channel, each with its wording,
     plus a daily limit. A note warns when a chosen channel is off.
  3. WhatsApp templates: the exact body and `{{n}}` variables to submit in
     WhatsApp Manager, the template name and language, and its approval
     state with a "Check now" button.
- **Super Admin → WhatsApp & SMS**: 24-hour counts; a readiness checklist
  for critical alerts on WhatsApp; Umar Techy's own accounts; the alert
  template; opening or closing Twilio for real messages, with a reason
  (closing switches every store's live account off).
- **Checkout**: "Send my order updates on WhatsApp", unticked by default,
  shown only when the store sends WhatsApp updates. Its hint asks for a
  phone number first. The same choice is on the account Profile page.
  Both have Urdu text.

## 6. Permissions

| Action | Needs |
|---|---|
| See the tab | `notifications.view` |
| Choose updates, limit, templates | `notifications.manage` |
| Connect / change / disconnect accounts, test messages, webhook details | `messaging.providers` + step-up for saving and disconnecting |
| Platform accounts, alert template, open/close Twilio | platform staff, privileged MFA, step-up |

## 7. Not built

- Push notifications (NOTIF-003) are still a stub.
- No Pakistani SMS provider adapter yet.
- No campaign or marketing messages by SMS or WhatsApp (Module 15
  §22–26). Messages here are transactional only.
- Inbound messages are not answered automatically. There is no
  conversation inbox; replies are only used for STOP / START.
- Per-message prices are not recorded.
- Template submission through Meta's API: templates are created in
  WhatsApp Manager, and the platform only checks their state.
- All platform staff have the same platform rights (an existing design
  since B16), so any platform staff member can change Umar Techy's
  messaging accounts. Finer platform roles are a separate item.
