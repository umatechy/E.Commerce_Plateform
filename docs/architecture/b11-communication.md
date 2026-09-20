# Phase B11 — Notifications & Communication Architecture (Module 21)

See `docs/development/b11-inspection-findings.md` for the scope decision and the
critical finding that `ConsumeOutboxEventJob` had been a placeholder since Phase
B0.

## B11 Is the Outbox's First Real Consumer

Since Phase B0, `ConsumeOutboxEventJob::handle()` re-resolved tenant context
correctly and marked every row `Published` — but never actually did anything with
the event. Every domain service since B5 (`OrderService`, `PaymentService`,
`ShipmentService`, `PromotionService`, `CampaignService`) correctly recorded
events; nothing ever consumed them for their real business purpose. B11 closes
this by adding one call — `NotificationEventRouter::route()` — before the row is
marked `Published`, leaving the existing retry/backoff/tenant-resolution logic
completely untouched.

## 27-Entity Data Model Collapsed to 4

Module 21 §43 lists 27 potential entities. B11 implements exactly four, following
the same 2-tier-collapse discipline used since Phase B7:

- **`NotificationTemplate`** — collapses Template + TemplateVersion + TemplateVariable.
  Immutability after publish (§44 Rule #3) is enforced by `NotificationTemplateService::update()`
  rejecting any edit once `is_published = true` — never a database constraint (which
  cannot express "immutable once a flag flips"), always the single controlling
  service.
- **`NotificationMessage`** — collapses Message + MessageRecipient + MessageContent
  + InAppNotification. A `read_at` column on this same row serves the in-app
  channel's read state, avoiding a duplicate table Module 21 itself warns against
  overlapping.
- **`NotificationDeliveryAttempt`** — collapses Delivery + DeliveryAttempt +
  DeliveryProviderReference. Append-only, mirrors Phase B7's `PaymentTransaction`/
  B8's `ShipmentTrackingEvent` exactly.
- **`NotificationSuppression`** — the unsubscribe/hard-bounce list. Consulted only
  for Marketing messages.

`Customer.marketing_email_opt_in` (Phase B10) is reused directly as the one
consent signal — not duplicated by a new preference table.

## Channel Abstraction

`NotificationChannelContract` (`providerName()`, `send()`) mirrors Phase B7's
`PaymentGatewayContract`/B8's `CarrierGatewayContract` exactly.
`NotificationChannelResolver` is the single channel-to-adapter mapping point.

- **Email** — real, functional, built on Laravel's own `Mail` facade. No live SMTP
  credential exists in this environment, so nothing is actually delivered, but the
  code path is genuine send logic, not a stub.
- **In-App** — real and complete; "delivery" for this channel is the database
  row's own existence.
- **SMS/WhatsApp/Push** — `UnconfiguredChannel` stubs that always return
  `channel_not_configured` (a PERMANENT failure, never retried) — no provider SDK,
  credential, or single named provider exists for this platform to build a
  deterministic double against (unlike Module 12/13's fully-named JazzCash/
  Easypaisa/courier context).

## Template Security (Non-Negotiable)

`NotificationTemplateRenderer` performs ONE non-recursive substitution pass of
`{{namespace.field}}` tokens against a caller-supplied, whitelisted flat array of
already-trusted server-side values. An unrecognized token is left as literal text
(fails safe). **Critically, a substituted value's OWN content is never re-scanned
for further tokens** — this is a deliberate security property (prevents a
value that happens to contain `{{...}}`-shaped text from being treated as more
template syntax) and was the source of a real bug found during this milestone
(see below).

## Consent, Suppression, and the Mandatory/Marketing Boundary

`NotificationService::send()` checks `messageType->requiresMarketingConsent()`
(true only for `Marketing`) before ever consulting `Customer.marketing_email_opt_in`
or `NotificationSuppression` — Transactional/System/Security/Administrative
messages are ALWAYS attempted (Module 21 §10, Non-Negotiable). A blocked marketing
message is marked `Suppressed` — a real, auditable delivery state — never silently
dropped.

## Delivery State Machine and Retry

`NotificationStateMachine` mirrors every prior phase's identical pattern.
`DeliverNotificationJob` (queued, idempotent, tenant context resolved from the
message row's own `store_id`) transitions `Queued → Processing → Sent/Delivered`
on success, or `Processing → RetryPending → Processing` (up to 5 attempts,
exponential backoff `[10, 30, 60, 300, 900]` seconds) on a retryable failure, or
straight to `Failed` for a `channel_not_configured` PERMANENT failure (Module 21
§29: "do not retry permanent failures indefinitely"). A retried/duplicate job
dispatch against an already-terminal message is a safe no-op (mirrors Phase B10's
identical `ProcessCampaignExecutionJob` fix).

## Two Bugs Found During This Milestone

1. `NotificationStateMachine`'s initial map did not allow `Processing ->
   RetryPending` — every single retryable failure would have thrown, since the
   most important part of the retry mechanism (§23/§29) was unreachable. Fixed.
2. An early draft of `NotificationEventRouter::handleShipmentCreated()` embedded a
   literal `{{shipment.tracking_number}}` token INSIDE another variable's own
   resolved value, expecting a second substitution pass that the renderer
   deliberately never performs (see Template Security above) — every shipped-order
   email would have shown the literal, unexpanded token instead of the real
   tracking number. Fixed by fully resolving the tracking-number text before it is
   ever passed to the renderer. A regression test for this exact bug exists in
   both `NotificationTemplateRendererTest` and `NotificationEventRouterTest`.

## Unsubscribe Link Security

A THIRD store secret (`notification_signing_secret`, separate from B7's
`payment_webhook_secret` and B8's `shipment_webhook_secret` — different purpose,
different rotation lifecycle) signs unsubscribe links via HMAC-SHA256 over
`(store_id, channel, destination)`. `UnsubscribeController` requires no
authentication (the entire point of a one-click unsubscribe) but rejects any
forged or cross-store signature outright (tested explicitly).

## Events Wired (Exact Names, None Invented)

| Outbox event (unchanged from its owning phase) | Notification produced |
|---|---|
| `order.created` (B5) | Transactional order-confirmation email |
| `order.cancelled` (B5) | Transactional cancellation email |
| `payment.initiated` (B7) | Transactional payment-received email |
| `payment.refunded` (B7) | Transactional refund-confirmation email |
| `shipment.created` (B8) | Transactional shipping-confirmation email |
| `marketing.recipient_queued` (B10) | Marketing email (consent/suppression re-checked at delivery time, with an unsubscribe link appended) |
| `marketing.abandoned_cart_detected` (B10) | Marketing reminder email |

A `NotificationTemplate` published for a given `(store, key, channel)` overrides
the embedded default subject/body (Module 21 §12 "Fallback Behavior") — every
event produces a real notification even before a store configures its own
templates.

## API Endpoints Added in B11

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET/POST/PUT | `/api/v1/notification-templates[/{id}]` | staff | publish-immutable |
| GET | `/api/v1/notification-messages` | staff | |
| GET | `/api/v1/notification-messages/{id}/attempts` | staff | |
| GET | `/api/v1/customer/notifications` | customer | paginated, own only |
| POST | `/api/v1/customer/notifications/{id}/read`, `/read-all` | customer | |
| PATCH | `/api/v1/customer/notification-preferences` | customer | reuses B10's field |
| GET | `/api/v1/public/notifications/unsubscribe` | none (signature) | |

## UI

Not built in B11, matching every backend-focused phase's own precedent.

## Deferred (see inspection findings for the full, explicit list)

Real SMS/WhatsApp/Push provider integration, provider routing/failover/health
monitoring, webhook-based delivery-status callbacks, localization/multi-locale
resolution, template approval workflow beyond publish-immutability, message
attachments/link tracking, communication cost metering beyond a basic entitlement
flag, storefront-specific display, push device-token registration, admin/
customer-facing UI.
