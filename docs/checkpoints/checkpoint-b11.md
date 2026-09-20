============================================================
PHASE B11 CHECKPOINT
============================================================

Phase:
Development Phase B

Module:
B11 — Notifications & Communication (Module 21)

Implementation Summary:
Implemented the central Notifications & Communication subsystem over the
existing outbox architecture, becoming the FIRST real consumer of events
recorded since Phase B5 (ConsumeOutboxEventJob had been a correctly-built but
functionally empty placeholder since Phase B0). Built a 4-table notification
data model (collapsed from Module 21's own 27-entity high-level list), a
provider/channel abstraction with real Email and In-App adapters and
contract-compliant stub SMS/WhatsApp/Push adapters, a safe (non-executing)
template rendering system with publish-time immutability, a 12-state delivery
state machine with idempotent queued retry, a consent/suppression enforcement
boundary that reuses Phase B10's existing marketing-consent field rather than
duplicating it, and a NotificationEventRouter wiring the exact, unmodified
event names/payloads already emitted by Order (B5), Payment (B7), Shipment
(B8), and Marketing (B10). Runtime execution remains deferred to VS Code -
nothing in this milestone has been executed against a real PHP/MySQL/Redis/
SMTP runtime.

Bugs Found and Fixed (design-time, caught before being left in the codebase):
1. NotificationStateMachine's initial transition map did not allow
   processing -> retry_pending, which would have made the entire retry
   mechanism (Module 21's own Steps 23/29) throw on every single retryable
   delivery failure. Fixed by adding the missing transition.
2. An early draft of NotificationEventRouter::handleShipmentCreated() embedded
   a literal {{shipment.tracking_number}} template token INSIDE another
   variable's own resolved value, expecting a second substitution pass that
   NotificationTemplateRenderer deliberately never performs (a security
   property preventing recursive-expansion injection). Every shipped-order
   email would have shown the literal, unexpanded token instead of the real
   tracking number. Fixed by fully resolving the tracking-number text before
   it is passed to the renderer, with regression tests added in two separate
   test files.

Architectural Decisions:
- The 27-entity high-level data model (Module 21 Sec43) is collapsed to
  exactly 4 tables (NotificationTemplate, NotificationMessage,
  NotificationDeliveryAttempt, NotificationSuppression), following the same
  2-tier-collapse discipline used since Phase B7's Payment/Attempt/Transaction
  simplification.
- Only Email and In-App channels are real; SMS/WhatsApp/Push are
  contract-compliant stubs returning a permanent channel_not_configured
  failure - no provider SDK, credential, or single named provider exists for
  this platform to build a deterministic double against.
- Customer.marketing_email_opt_in (Phase B10) is reused directly as the one
  consent signal - not duplicated by a new NotificationPreference table.
- Mandatory (transactional/system/security/administrative) messages are
  ALWAYS attempted regardless of marketing consent/suppression; only Marketing
  messages are ever subject to those checks (Module 21 Sec10, Non-Negotiable).
- Unsubscribe links use a THIRD, separate per-store secret
  (notification_signing_secret) from Payment's and Shipping's own webhook
  secrets - different purpose, different rotation lifecycle.

Marketing/Notification Domain:
NotificationTemplate (publish-immutable), NotificationMessage (collapsed
Message+Recipient+Content+InAppNotification, with read_at serving the in-app
read state), NotificationDeliveryAttempt (append-only ledger),
NotificationSuppression (unsubscribe/hard-bounce list).

Coupon/Campaign Integration (Notification Boundary):
No promotion or campaign logic was duplicated. NotificationEventRouter
consumes marketing.recipient_queued and marketing.abandoned_cart_detected
(Phase B10, unchanged) to create real notifications; Campaign/CampaignRecipient
rows themselves are never touched by B11.

Eligibility/Consent Engine:
NotificationService::send() is the sole gate - checks consent/suppression only
for Marketing-type messages, using Customer.marketing_email_opt_in and the new
NotificationSuppression table, never a duplicated frequency/eligibility
mechanism (Phase B10's 24-hour campaign-level cooldown remains the only
frequency control).

Channel/Provider Abstraction:
NotificationChannelContract + NotificationChannelResolver, mirroring Phase
B7/B8's GatewayResolver/CarrierResolver exactly. EmailChannel (real, Laravel
Mail-based), InAppChannel (real, complete), UnconfiguredChannel stub for
SMS/WhatsApp/Push.

Template System:
NotificationTemplate with is_published immutability guard
(NotificationTemplateService is the sole writer), safe non-recursive
{{namespace.field}} substitution via NotificationTemplateRenderer, HTML
escaping for the email channel, embedded code-level fallback content when no
published template exists for a given (store, key, channel).

Delivery State Machine and Retry:
NotificationStatus's exact 12-state list (Module 21 Sec27) with
NotificationStateMachine centralizing every transition. DeliverNotificationJob
(queued, idempotent, tenant context resolved from the message row's own
store_id) implements exponential backoff up to 5 attempts for retryable
failures, and immediate permanent failure for channel_not_configured. A
retried/duplicate job dispatch against an already-terminal message is a safe
no-op.

Event/Outbox Integration:
NotificationEventRouter is the platform's first real ConsumeOutboxEventJob
consumer, wiring order.created, order.cancelled (B5), payment.initiated,
payment.refunded (B7), shipment.created (B8), marketing.recipient_queued,
marketing.abandoned_cart_detected (B10) - exact event names/payloads, none
invented or renamed. An unrecognized event_type is silently ignored.

Consent/Preferences:
Customer.marketing_email_opt_in remains the single source of truth (Phase
B10, reused). A new customer-facing PATCH endpoint lets a customer toggle it
themselves; no staff endpoint can read or write it (customer preferences
stay customer-only, per this milestone's own instruction).

Database/Migration Summary:
6 new/modified migrations: notification_templates, notification_messages,
notification_delivery_attempts, notification_suppressions (all new tables),
stores.notification_signing_secret (additive, backfilled per-row from the
start - the exact bulk-UPDATE mistake Phase B7 made and B8 avoided is not
repeated here either). No existing table's existing column altered, renamed,
or removed. No destructive operation performed.

API Summary:
GET/POST/PUT /api/v1/notification-templates[/{id}] (staff), GET
/api/v1/notification-messages[/{id}/attempts] (staff), GET
/api/v1/customer/notifications (customer, paginated, own only), POST
/api/v1/customer/notifications/{id}/read and /read-all (customer), PATCH
/api/v1/customer/notification-preferences (customer), GET
/api/v1/public/notifications/unsubscribe (no auth, signature-verified).

Admin UI Summary:
Not built - matches every backend-focused phase's own precedent.

Storefront UI Summary:
Not built - same precedent.

Security Review:
Performed (docs/security/b11-security-review.md) - a 30-item checklist
derived from Module 21's own Data Integrity/Security/Anti-Abuse rule
sections, reviewed end-to-end, plus a B0-B10 regression confirmation. 2
design-time issues found and fixed. 3 known limitations documented (no
dedicated rate limiting beyond platform default; no provider-message-ID
uniqueness constraint, deferred alongside real provider integration; no
anti-abuse control set beyond existing frequency cooldown and consent
checks).

Tests Added:
46 new test methods across 8 Feature test files:
- tests/Feature/Notifications/NotificationTemplateRendererTest.php - 6 methods
- tests/Feature/Notifications/NotificationStateMachineTest.php - 7 methods
- tests/Feature/Notifications/NotificationServiceTest.php - 7 methods
- tests/Feature/Notifications/DeliverNotificationJobTest.php - 4 methods
- tests/Feature/Notifications/NotificationEventRouterTest.php - 6 methods
- tests/Feature/Notifications/UnsubscribeControllerTest.php - 5 methods
- tests/Feature/Notifications/NotificationAdminTest.php - 6 methods
- tests/Feature/Notifications/CustomerNotificationTest.php - 5 methods
Plus 2 new model factories (NotificationTemplate, NotificationMessage).
Combined with all carried-forward B0-B10 tests: 380 test methods total across
the whole suite (verified by direct grep count, not estimated).

Tests Actually Executed:
NONE. No PHP, Composer, MySQL, Redis, or SMTP runtime is available in this
Claude App sandbox.

Tests Not Executed:
All 380 test methods, including all 46 new to this milestone. No live email/
SMS/WhatsApp/push delivery was ever attempted or claimed.

Static Inspections Performed (EXECUTED vs INSPECTED vs NOT EXECUTED - nothing
below was EXECUTED):
- Source inspection of every new/modified file against Module 21's
  requirements.
- A lightweight Node.js/Python-based brace/parenthesis balance check across
  all new/modified PHP files (one false-positive from string literals
  containing `{{` was independently verified as a false alarm via a
  string-literal-stripped recount, not a real syntax error).
- Route inspection: confirmed staff notification routes are inside the
  staff.principal-guarded group, customer routes inside the customer-
  authenticated group, and the unsubscribe route carries no auth middleware
  at all.
- Migration inspection: confirmed foreign keys, unique constraints
  (store_id+idempotency_key on notification_messages; store_id+channel+
  destination on notification_suppressions), and index coverage for the
  query patterns NotificationService/DeliverNotificationJob/
  CustomerNotificationController actually use.
- Cross-reference check: confirmed NotificationEventRouter uses the EXACT
  event names and payload keys OrderService/PaymentService/ShipmentService/
  Marketing services already emit, with zero changes to any of those
  classes' existing methods.
- ConsumeOutboxEventJob diff inspection: confirmed the pre-existing retry
  count, backoff schedule, and tenant-resolution call were left completely
  unchanged - only one new line (the router call) was added before the
  existing "mark Published" line.

Known Limitations:
- Nothing in this milestone has been executed against a real runtime.
- No live SMS/WhatsApp/Push provider integration exists (by design, per this
  milestone's explicit prohibition on fabricating live delivery).
- No dedicated rate limiting beyond the platform default on notification
  endpoints.
- No provider-message-ID uniqueness constraint (deferred alongside real
  provider integration).
- No dedicated anti-abuse/messaging-fraud control set beyond existing
  frequency cooldown and consent checks.

Deferred Functionality:
Real SMS/WhatsApp/Push provider integration, provider routing/failover/health
monitoring, webhook-based delivery-status callbacks, localization/multi-
locale resolution, template approval workflow beyond publish-immutability,
message attachments/link tracking, communication cost metering beyond a
basic entitlement flag, storefront-specific notification display, push
device-token registration, admin/customer-facing UI. Full list with
rationale in docs/development/b11-inspection-findings.md.

Files Changed:
New: app/Domain/Notifications/ (Models: NotificationTemplate,
NotificationMessage, NotificationDeliveryAttempt, NotificationSuppression,
NotificationMessageType, NotificationChannel, NotificationStatus,
DeliveryAttemptResult, RecipientType; Channels: NotificationChannelContract,
NotificationSendResult, EmailChannel, InAppChannel, UnconfiguredChannel,
NotificationChannelResolver; Services: NotificationStateMachine,
NotificationTemplateRenderer, NotificationService, NotificationTemplateService,
NotificationEventRouter; Jobs: DeliverNotificationJob; Policies:
NotificationPolicy; Http/{Controllers: NotificationTemplateController,
NotificationMessageController, CustomerNotificationController,
UnsubscribeController; Requests: SaveTemplateRequest,
UpdateNotificationPreferencesRequest; Resources: NotificationTemplateResource,
NotificationMessageResource, NotificationDeliveryAttemptResource}; Exceptions:
2 classes). New: 6 migrations, 2 factories, 8 test files. Modified:
ConsumeOutboxEventJob (+router call, additive), Store model
(+notification_signing_secret fillable/hidden), StoreObserver (+secret
generation), PermissionSeeder (+notifications.view/manage), PackageSeeder
(+notifications.basic for all 3 tiers), StoreObserver permissions (+for
Manager), routes/api_v1.php, routes/api_v1_customer.php, routes/
api_v1_public.php (+notification/unsubscribe routes).

Git Status:
Verified by direct execution (git status) before this checkpoint was written:
all files listed above are new/modified/staged relative to the previous
commit (b62c512 / 3bf153d). No files outside the Notifications domain, the
one-line ConsumeOutboxEventJob addition, Store's additive secret column, and
documentation were touched.

Git Commit Status:
Commit created: bb08fc8 - "Phase B11: Notifications & Communication (Module
21)". Verified by direct execution (git log --oneline after the commit):
working tree clean, history now shows twelve real commits: 5dcb815 (Phase
B0-B5), b12ae2b (B5 checkpoint correction), 47d6a1c (Phase B6), 24b7bd3 (B6
checkpoint correction), 66d66a5 (Phase B7), 6888a16 (B7 checkpoint
correction), 3184400 (Phase B8), 3259813 (B8 checkpoint correction), e781aad
(Phase B9), dc065c9 (B9 checkpoint correction), b62c512 (Phase B10), 3bf153d
(B10 checkpoint correction), bb08fc8 (this milestone). No fabricated
incremental history.

Recommended Next Milestone:
Phase B12 - per the approved module sequence, Module 16 (SEO & Content
Management) or Module 22 (Reports, Analytics & Dashboard) are the next
natural candidates. Recommend Module 22 next: B9 explicitly deferred
attribution/analytics to Module 22, B10 deferred campaign metrics/attribution
to the same, and B11's own NotificationDeliveryAttempt/NotificationMessage
ledgers are now additional raw, queryable data Module 22 could aggregate
alongside them - consolidating three phases' worth of deferred reporting
needs into one coherent analytics milestone, the same pattern this project's
own checkpoint history has followed since Phase B9 recommended Phase B10, and
B10 recommended B11. Phase B12's own Step 1 should inspect every ledger table
built since B5 (order_timeline_events, payment_transactions,
shipment_tracking_events, promotion_usages, campaign_recipients,
notification_delivery_attempts) before designing any new reporting schema.
