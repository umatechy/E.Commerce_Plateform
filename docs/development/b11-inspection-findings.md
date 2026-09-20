# Phase B11 — Step 1: Inspection + Scope Decision (Notifications & Communication: Module 21)

## Inspection of Existing Code — Critical Finding

`ConsumeOutboxEventJob` (Phase B0) has existed since the very first milestone as a
**placeholder**: its `handle()` method re-resolves tenant context from the row's
own `store_id` (correct, unchanged) but then does nothing except mark the row
`Published`. Its own docblock states: "Concrete per-event-type routing is added as
each owning module... is implemented." **No phase from B5 through B10 ever
actually did this** — every domain service (`OrderService`, `PaymentService`,
`ShipmentService`, `PromotionService`, `CampaignService`) correctly calls
`RecordsOutboxEvents::recordEvent()` to persist an event, and `outbox:publish`
correctly dispatches `ConsumeOutboxEventJob` for each pending row, but nothing
downstream has ever consumed an event for its actual business purpose. **B11 is
the first real consumer** — this milestone extends `ConsumeOutboxEventJob::handle()`
additively (a new `NotificationEventRouter` call before the row is marked
`Published`, not a rewrite of the retry/tenant-resolution logic that already
works correctly) rather than replacing the file.

`PublishOutboxEvents` (the scheduler command) is correctly placed in
`app/Console/Commands/` (Laravel's default auto-discovered location) — unlike the
domain-namespaced commands from B4/B10, this one was never affected by the
`withCommands()` registration gap B10 found and fixed.

## Other Inspection Findings

- `Customer.marketing_email_opt_in` (Phase B10) — reused directly as the ONE
  marketing-email consent signal. **Not duplicated** by a new
  `NotificationPreference` table for this dimension — Module 21's own Data
  Integrity Rules say "Module 10 remains authoritative for customer preferences,"
  and since Module 10 doesn't exist, B10's field is the closest authoritative
  source that already exists. A new `NotificationSuppression` table is added for
  the DIFFERENT concept of hard suppression (bounce/explicit unsubscribe — a
  stronger, provider-driven signal than a simple preference toggle).
- `CampaignRecipient` (Phase B10) — NOT reused as the Notification "Message"
  entity. Module 15 Final Rule #9 and B10's own architecture explicitly frame
  `CampaignRecipient` as "handed off to the Module 21 boundary" — B11's
  `NotificationEventRouter` consumes the `marketing.recipient_queued` outbox event
  (which itself was emitted alongside each `CampaignRecipient` row, Phase B10) to
  create a real `NotificationMessage`, rather than overloading
  `CampaignRecipient` with delivery-state columns Module 15 deliberately kept out
  of its own scope.
- `OrderTimelineEvent`/`PaymentTransaction`/`ShipmentTrackingEvent` — none of
  these are notification/delivery records; B11 creates none of its own audit
  entries inside those tables (Module 21 Step 5's explicit "avoid overlapping
  tables" instruction).
- No existing mail/SMS/WhatsApp/push infrastructure exists anywhere in the
  repository — B11 is a clean addition for the channel layer itself, built on top
  of Laravel's own built-in `Mail` facade (available, configurable, but not
  connected to a live SMTP credential in this environment).
- No regressions found in B0-B10 during inspection.

## Architectural Decision — 27-Entity Data Model Collapsed to 4 (Module 21 §43, No Numeric Scope Given)

Module 21 §43 lists 27 potential high-level entities. Per the same 2-tier-
simplification discipline this project has applied since Phase B7's
Payment/Attempt/Transaction collapse, B11 implements exactly **four** tables:

- **`NotificationTemplate`** — collapses `CommunicationTemplate` +
  `TemplateVersion` + `TemplateVariable`. Immutability (§44 Rule #3, "Published
  templates are immutable") is enforced by rejecting any update to a template
  once `is_published = true` — to change a published template's content, a new
  template row is created (same "snapshot, don't mutate" philosophy used
  throughout this codebase since `OrderItem`'s own snapshot fields). Variables
  are not a separate table — they are documented, whitelisted placeholder tokens
  resolved at render time (see Template Security below), not stored per-template
  metadata rows.
- **`NotificationMessage`** — collapses `Message` + `MessageRecipient` +
  `MessageContent` + `InAppNotification`. One row already IS one message to one
  resolved recipient with its rendered content baked in (no separate "recipient"
  join needed since B11 never fans one message out to multiple recipients); a
  `read_at` column on this same row serves the in-app channel's read/unread
  state (§21), avoiding a duplicate `InAppNotification` table that would overlap
  with this one, per Module 21's own explicit warning.
- **`NotificationDeliveryAttempt`** — collapses `Delivery` + `DeliveryAttempt` +
  `DeliveryProviderReference`. Append-only ledger, same 2-in-1 pattern as Phase
  B7's `PaymentTransaction` and B8's `ShipmentTrackingEvent`.
- **`NotificationSuppression`** — collapses `Suppression` + the destination-level
  half of `ChannelPreference`/`ConsentReference` (the other half,
  `marketing_email_opt_in`, already exists on `Customer` from B10 and is reused,
  not duplicated).

**Not built as separate tables** (documented, not silently dropped):
`CommunicationChannel`/`CommunicationProvider`/`ProviderAccount`/
`ProviderCapability` (channels/providers are code-level adapters implementing a
shared contract — `NotificationChannelContract` — not database rows, since no
real multi-provider routing/failover exists yet to configure); `FrequencyRule`
(B10 already established a documented fixed 24-hour cooldown for marketing at the
Campaign-execution level — B11 does not add a second, competing frequency
mechanism); `MessageSchedule` (B11's messages are dispatched immediately upon an
eligible event, never scheduled for a future send time — no use case in this
milestone's actual event sources needs it); `MessageAttachment`/`MessageLink`
(no attachment/link-tracking use case exists among the events B11 actually
wires); `ProviderHealth`/`CommunicationUsage`/`CommunicationQuota`/
`CommunicationFailure` (Module 22's reporting/analytics territory, consistent
with every prior phase's identical deferral of analytics beyond a raw, queryable
ledger); `SenderIdentity`/`SenderVerification` (no real provider account exists
to verify a sender identity against); `CommunicationAuditReference` (the
`NotificationMessage`/`NotificationDeliveryAttempt` tables themselves already
serve as the auditable record — a separate reference table would only duplicate
their own primary keys).

## Architectural Decision — Channel Scope: Email and In-App Are Real; SMS/WhatsApp/Push Are Contract-Only Stubs

Consistent with Phase B7's `MockRedirectGateway`/B8's `MockCourierCarrier`
precedent for "no live credentials exist" channels — except here, unlike Payment/
Shipping, Module 21 does not give a single fully-specified worked example for a
mock SMS/WhatsApp/push gateway to build a deterministic double against. **Decision**:
- **Email** — a real, functional `EmailChannel` built on Laravel's own `Mail`
  facade. This genuinely sends through whatever mail transport is configured
  (`.env` `MAIL_MAILER`) — in this environment, no live SMTP credential exists, so
  no message is actually delivered, but the CODE PATH is real and correct, not a
  stub.
- **In-App** — a real, functional `InAppChannel` — "delivery" for this channel
  IS the database row's own existence and correct tenant/customer scoping; there
  is no external provider at all, so this channel is complete and fully
  operational without any environment dependency.
- **SMS/WhatsApp/Push** — `NotificationChannelContract`-compliant stub adapters
  that immediately return a `channel_not_configured` failure result. The
  contract, routing, and message/delivery-attempt recording around them are
  fully real and correct; only the actual provider call is absent, because no
  provider SDK, credential, or even a single concrete provider name is specified
  by Module 21 for this platform to build a deterministic double against (unlike
  Module 12/13's named JazzCash/Easypaisa/courier context). Adding a real SMS/
  WhatsApp/push provider later means implementing one adapter class — zero
  changes to `NotificationService`, `DeliverNotificationJob`, or any other
  caller.

## Architectural Decision — Template Variable Security (Module 21 §14, Non-Negotiable)

`NotificationTemplateRenderer` performs pure string substitution of
`{{namespace.field}}`-shaped tokens against an explicit, whitelisted, flat
key-value array the CALLER builds from trusted server-side model data (e.g.
`['customer.name' => $customer->name, 'order.number' => $order->order_number]`)
— never `eval()`, never a templating engine with method-call/property-access
syntax, never raw HTML/SQL interpolation. An unrecognized token in a template is
left as literal, visible text (fails safe, never silently expands to something
unintended) rather than throwing or executing anything. Output is escaped for
the target channel (HTML-escaped for email HTML bodies; left as plain text for
SMS/in-app, since no channel in B11's scope renders HTML).

## Architectural Decision — Consent, Suppression, and Message-Type Boundary (Module 21 §9-10, Non-Negotiable)

- **Transactional/System/Security/Administrative** messages are ALWAYS attempted
  regardless of `Customer.marketing_email_opt_in` or `NotificationSuppression` —
  Module 21 §10's explicit rule that marketing preferences must never disable
  mandatory non-marketing communication.
- **Marketing** messages check, in order: (1) `Customer.marketing_email_opt_in`
  must be `true`, (2) no active `NotificationSuppression` row for that
  destination+channel. Either check failing marks the message `Suppressed`
  (a real Module 21 §27 delivery state) rather than silently dropping it —
  auditable, never invisible.
- Frequency capping remains B10's existing 24-hour Campaign-execution-level
  cooldown; B11 does not add a second, independent frequency mechanism for
  marketing messages it consumes from `marketing.recipient_queued` (that event
  is only ever emitted once B10 has already applied its own cooldown check).

## Scope Decision (Module 21 spans 49+ sections — smaller than B8-B10's 108-section
modules, but still requires firm boundaries)

**B11 implements**: the 4-table data model above, a channel abstraction with real
Email/In-App adapters and stub SMS/WhatsApp/Push adapters, a template system with
publish-time immutability and safe variable substitution, a delivery state
machine (Module 21 §27's exact 12-state list), an idempotent queued delivery job,
an append-only delivery-attempt ledger, a suppression system (unsubscribe),
reuse of B10's marketing-consent field, a `NotificationEventRouter` that becomes
the outbox's first real consumer — wiring `order.created`, `order.cancelled`
(Phase B5), `payment.initiated`, `payment.refunded` (Phase B7), `shipment.created`
(Phase B8), `marketing.recipient_queued`, `marketing.abandoned_cart_detected`
(Phase B10) to real (or stub) notification delivery — retry/backoff, and
staff-facing + customer-facing APIs (in-app notification retrieval, preference
toggle reusing B10's field, public unsubscribe endpoint).

**Explicitly deferred** (named so nothing is silently dropped):
- Real SMS/WhatsApp/Push provider integration (§18-20) — no provider credentials,
  SDK, or single named provider exists for this platform to build against; the
  contract and stub exist, the live call does not.
- Provider routing, failover, health monitoring (§32-34) — only one (real or
  stub) adapter per channel exists; there is nothing to route between or fail
  over across yet.
- Webhook-based delivery-status callbacks from providers (§35-36) — no real
  provider exists to send one; `NotificationDeliveryAttempt`'s schema is
  provider-reference-ready for a future phase to add a signature-verified
  webhook endpoint mirroring Phase B7/B8's exact pattern.
- Localization/multi-locale templates (§15) — `NotificationTemplate.locale`
  exists as a column (defaulted `'en'`) but no locale-resolution/fallback logic
  is built; only one locale is ever used in B11.
- Template approval workflow (§37) beyond the publish-immutability rule itself.
- Message attachments/media, message-link tracking (§38-39).
- Communication cost metering, package-based communication limits beyond a
  simple `notifications.basic` entitlement flag (§40-41) — no numeric limit is
  given by the specification; none invented.
- Storefront-specific notification display beyond the generic in-app channel
  (§22) — no storefront frontend exists yet (consistent with every backend-
  focused phase since B6).
- Push device-token registration/management (§19's own detailed requirements) —
  the Push channel is a stub with no send capability yet, so there is no live
  consumer for device tokens to register against; deferred alongside the Push
  provider itself.
- Admin/customer-facing UI (React components) — matches every backend-focused
  phase's own precedent.

None of these are abandoned — each is named so Phase B12+'s own Step 1
inspection finds this documented list.

## Bug Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

`NotificationStateMachine`'s initial transition map did not allow `processing ->
retry_pending`, which would have made `DeliverNotificationJob`'s own retry path
(the most important part of Module 21 §23/§29) throw
`InvalidNotificationStateTransitionException` on every single retryable failure —
a delivery attempt that failed but had retries remaining could never actually be
marked `RetryPending`. Caught while writing `DeliverNotificationJob`, before being
left in the codebase — fixed by adding `retry_pending` to `processing`'s allowed
transitions (a delivery attempt failing mid-processing and needing another try is
a direct `Processing -> RetryPending` transition, not a two-step
`Processing -> Failed -> RetryPending`).

## Second Bug Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

An early draft of `NotificationEventRouter::handleShipmentCreated()` embedded a
literal `{{shipment.tracking_number}}` template token INSIDE another variable's
own resolved VALUE (`shipment.tracking_line`), expecting the renderer to expand it
in a second pass. `NotificationTemplateRenderer` deliberately performs only ONE
substitution pass over the original template string (a security property — see
its own docblock — that prevents a malicious or malformed variable value from
ever being treated as further template syntax, i.e. no recursive-expansion
injection vector). This meant the nested token would have appeared as literal,
unexpanded text (`{{shipment.tracking_number}}`) in every actual shipped-order
email. Fixed by fully resolving the tracking-number text directly into
`shipment.tracking_line`'s own value before it is ever passed to the renderer.
