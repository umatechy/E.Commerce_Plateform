============================================================
PHASE B10 CHECKPOINT
============================================================

Phase:
Development Phase B

Module:
B10 — Marketing & Customer Engagement (Module 15)

Implementation Summary:
Implemented a boundary-scoped Marketing domain over the existing Cart/Order/
Customer/Promotion foundation: a whitelisted, injection-safe customer segment
rule engine (computed from existing Order/Customer data, since Module 10's own
segmentation/consent system was never built), a full Campaign lifecycle with
state machine, idempotent queued campaign execution that creates auditable
CampaignRecipient records and outbox events without ever sending a real message
(Module 21, the next phase, owns actual delivery), and one concrete trigger -
abandoned-cart detection. Runtime execution remains deferred to VS Code - nothing
in this milestone has been executed against a real PHP/MySQL/Redis runtime.

Bugs Found and Fixed (design-time, caught before being left in the codebase):
1. bootstrap/app.php never registered domain-namespaced console commands at all
   - a PRE-EXISTING gap since Phase B4 (ExpireStaleReservations was never
   actually discoverable by Artisan despite being scheduled), found while wiring
   B10's own new DetectAbandonedCarts command. Fixed for both commands via an
   explicit withCommands([...]) registration.
2. ProcessCampaignExecutionJob::handle() had no guard against re-running against
   an already-terminal (e.g. Completed) campaign, which would have turned an
   idempotent-by-design retry into a hard InvalidCampaignStateTransitionException
   (terminal states have no outgoing transitions, not even to themselves). Fixed
   with an early-return terminal-state check.
3. AbandonedCartDetectionTest's own test helper tried to backdate Cart.updated_at
   via a plain update() call, which silently no-ops since that column isn't
   mass-assignable - fixed with forceFill() plus disabled instance timestamps
   before any test was run against it.

Architectural Decisions (Module 15 defers consent/segmentation to a
never-built Module 10, and leaves several other points explicitly undefined -
documented per this milestone's own instructions rather than silently assumed):
- Minimal consent/segment foundation: Customer.marketing_email_opt_in (the ONE
  consent channel implemented) and MarketingSegment computed directly from
  existing Order/Customer data via a whitelisted rule engine - neither requires
  or reimplements a Module-10 group/tag/consent system.
- Campaign execution is boundary-only: creates CampaignRecipient rows and
  outbox events; never calls a real email/SMS/WhatsApp/push API.
- Frequency control: one marketing action per customer per 24 hours (no numeric
  value given by the specification - a documented, conservative default).
- Audience evaluation timing: execution-time (re-evaluated on every actual job
  run, including resume-from-pause), not frozen at scheduling time.
- Timezone: Campaign.scheduled_at is UTC/server-time only - no Store.timezone
  column exists to build proper timezone-aware scheduling on.

Marketing Domain:
MarketingSegment (whitelisted dynamic rules), Campaign (email-only channel,
all_customers/segment audience, optional Promotion reference, full state
machine), CampaignRecipient (idempotent per campaign+customer, no PII copied
beyond the foreign key).

Campaign System:
Draft -> Scheduled/Active -> Paused/Completed/Cancelled/Failed/Archived, all
transitions centralized in CampaignStateMachine. CampaignService is the sole
writer of status; idempotency_key prevents duplicate execution dispatch.

Customer Segmentation:
Dynamic, rule-based segments evaluated via MarketingSegmentService against an
explicit field/operator whitelist (total_orders_count, total_spent_minor,
last_order_at, registered_at; operators >=, <=, =, >, <) - never raw SQL, never
an arbitrary expression. All rules combined with AND. Validated server-side
before a segment is ever persisted, and re-tested at the API layer with a
SQL-injection-shaped input.

Audience System:
all_customers (every tenant-scoped Customer) or segment (dynamic rule match).
Audience resolution is always tenant-scoped by construction (Customer::query()
carries the same global scope every other domain relies on) - a Store A
campaign structurally cannot enumerate Store B's customers.

Trigger/Automation Summary:
One concrete trigger implemented: abandoned-cart detection (a scheduled console
command, every 15 minutes, idempotent via Cart.abandoned_marketing_notified_at).
All other Module 15 trigger types (welcome, first-purchase, post-purchase,
win-back, birthday, product-launch, back-in-stock, price-drop, cross/up-sell,
reorder) are explicitly deferred - the generic Campaign entity does not need
restructuring to add them later.

Scheduling/Execution Summary:
Campaigns activate immediately or schedule for a future UTC timestamp.
Execution is asynchronous (queued job, Bus::dispatch), idempotent
(campaign-level idempotency_key + recipient-level unique constraint), and
processes the whole resolved audience in one job run with no bounded-batch
chunking beyond what the audience collection itself represents (documented
scope - no send-window/time-of-day restriction was built).

Promotion Integration:
Campaign.promotion_id is a plain, read-only reference. No promotion
eligibility, discount calculation, or usage-limit logic was duplicated -
Module 14's PromotionEligibilityEngine/PromotionService remain completely
unchanged and fully authoritative.

Notification Boundary:
No email/SMS/WhatsApp/push sending code exists anywhere in B10. Every
campaign/recipient/trigger action ends at an outbox event
(marketing.campaign_activated, marketing.campaign_completed,
marketing.recipient_queued, marketing.abandoned_cart_detected) - Module 21
(the next phase per the project's own instruction) is the intended consumer.

Consent/Preferences:
Customer.marketing_email_opt_in, default false (opt-in required). Checked
unconditionally before every CampaignRecipient creation - no code path
bypasses it. SMS/WhatsApp/push consent categories are not implemented (no
channel infrastructure exists yet).

Metrics/Attribution Summary:
No dedicated metrics or attribution model was built - Module 15's own
instruction that this belongs to Module 22, and that attribution "must be
deterministic and auditable" (best honored by building nothing ad-hoc). The
CampaignRecipient ledger itself is the raw, queryable foundation a future
Module 22 integration can aggregate.

Queue/Job Summary:
ProcessCampaignExecutionJob (ShouldQueue, idempotent, tenant context resolved
from the Campaign row's own store_id - never from an untrusted job payload
field). Not executed against a real queue worker in this environment (no
Redis/queue runtime available) - tested via direct handle() invocation and
Bus::fake() dispatch assertions instead.

Entitlement Integration:
marketing.basic feature flag added for all 3 package tiers via the existing
EntitlementService pattern. No numeric campaign/segment/audience-size limit
was invented - Module 15 does not specify one.

Database/Migration Summary:
6 new/modified migrations: customers.marketing_email_opt_in (additive column),
marketing_segments, campaigns, campaign_recipients (new tables),
carts.abandoned_marketing_notified_at (additive column). No existing table's
existing column altered, renamed, or removed. No destructive operation
performed.

API Summary:
GET/POST /api/v1/marketing/segments, GET /api/v1/marketing/segments/{id}/preview,
GET/POST /api/v1/campaigns[/{id}], POST /api/v1/campaigns/{id}/activate,pause,
resume,cancel, GET /api/v1/campaigns/{id}/recipients (all staff-facing).

Admin UI Summary:
Not built - matches Phase B6-B9's own precedent of no frontend built in these
backend-focused phases.

Storefront UI Summary:
Not built - same precedent; no customer-facing marketing endpoint exists in B10
at all.

Events/Outbox Summary:
marketing.campaign_activated, marketing.campaign_completed,
marketing.recipient_queued, marketing.abandoned_cart_detected - all
transactionally consistent with the state change they describe, all carrying
their own store_id via the unmodified RecordsOutboxEvents mechanism (ADR-004).

Audit Summary:
CampaignRecipient rows are effectively append-only (status set once at
creation, never mutated afterward - verified by inspection). No separate
dedicated audit log entry is written for admin campaign/segment CRUD beyond
standard authorization/validation (documented limitation, consistent with
Phase B9's identical scope decision for promotion admin actions).

Security Review:
Performed (docs/security/b10-security-review.md) - this milestone's full
30-item checklist reviewed end-to-end, plus a B0-B9 regression confirmation. 3
design-time issues found and fixed (including one pre-existing since Phase B4).
3 known limitations documented (no dedicated marketing-endpoint rate limiting;
unpaginated segment preview; only email consent implemented).

Tests Added:
37 new test methods across 6 Feature test files:
- tests/Feature/Marketing/MarketingSegmentServiceTest.php - 9 methods
- tests/Feature/Marketing/CampaignStateMachineTest.php - 6 methods
- tests/Feature/Marketing/CampaignExecutionTest.php - 6 methods
- tests/Feature/Marketing/AbandonedCartDetectionTest.php - 5 methods
- tests/Feature/Marketing/CampaignAdminTest.php - 9 methods
- tests/Feature/Marketing/CampaignConcurrencyTest.php - 2 methods
Plus 2 new model factories (MarketingSegment, Campaign). Combined with all
carried-forward B0-B9 tests: 334 test methods total across the whole suite
(verified by direct grep count, not estimated).

Tests Actually Executed:
NONE. No PHP, Composer, MySQL, or Redis runtime is available in this Claude App
sandbox.

Tests Not Executed:
All 334 test methods, including all 37 new to this milestone. The concurrency
test (CampaignConcurrencyTest) is explicitly a sequential simulation, not
genuine parallel load - flagged as the highest-priority scenario to verify for
real once a runtime is available, consistent with every prior phase's identical
precedent. Queue execution itself was tested via direct handle() invocation and
Bus::fake() dispatch assertions, never a real queue worker.

Static Inspections Performed (EXECUTED vs INSPECTED vs NOT EXECUTED - nothing
below was EXECUTED):
- Source inspection of every new/modified file against Module 15's
  requirements.
- A lightweight Node.js-based brace/parenthesis balance check across all new/
  modified PHP files - no mismatches found.
- Route inspection: confirmed staff marketing/campaign routes are inside the
  staff.principal-guarded group.
- Migration inspection: confirmed foreign keys, unique constraints
  (campaign_id+customer_id on campaign_recipients; store_id+idempotency_key on
  campaigns), and index coverage for the query patterns
  MarketingSegmentService/ProcessCampaignExecutionJob actually use.
- Cross-reference check: confirmed Campaign.promotion_id is read-only and no
  code path in B10 duplicates PromotionEligibilityEngine/PromotionService logic.
- Command registration inspection: confirmed bootstrap/app.php's new
  withCommands() call actually resolves both ExpireStaleReservations (Phase B4)
  and DetectAbandonedCarts (Phase B10) by directory path.
- Regression re-check of OrderStateMachine/ShipmentStateMachine/
  PaymentStateMachine (0 marketing-related references) and CheckoutService (0
  Marketing/Campaign references), confirming B10 never touches the checkout
  path.

Known Limitations:
- Nothing in this milestone has been executed against a real runtime.
- No dedicated rate limiting on marketing endpoints beyond the platform
  default.
- Segment preview has no pagination of its own.
- Only email marketing consent exists; SMS/WhatsApp/push are not implemented.
- No dedicated audit log entries for admin campaign/segment CRUD beyond
  standard authorization/validation.

Deferred Functionality:
SMS/WhatsApp/push marketing channels, welcome/first-purchase/post-purchase/
review-request/win-back/birthday/product-launch/back-in-stock/price-drop/
cross-sell/up-sell/reorder campaign triggers (only abandoned-cart is built),
loyalty/referral/customer-advocacy foundations, campaign landing pages,
storefront marketing display, A/B testing, personalization, AI marketing,
marketing content approval/template versioning, revenue attribution/campaign
analytics, send windows/batch tuning, marketing exports/data retention,
monitoring dashboards, admin/storefront UI. Full list with rationale in
docs/development/b10-inspection-findings.md.

Files Changed:
New: app/Domain/Marketing/ (Models: MarketingSegment, Campaign,
CampaignRecipient, CampaignStatus, CampaignObjective, CampaignAudienceType,
CampaignRecipientStatus; Services: CampaignStateMachine, MarketingSegmentService,
CampaignService, AbandonedCartDetectionService; Jobs:
ProcessCampaignExecutionJob; Console: DetectAbandonedCarts; Policies:
MarketingPolicy; Http/{Controllers: SegmentController, CampaignController;
Requests: SaveSegmentRequest, SaveCampaignRequest, ActivateCampaignRequest;
Resources: SegmentResource, CampaignResource, CampaignRecipientResource};
Exceptions: 2 classes). New: 6 migrations, 2 factories (MarketingSegment,
Campaign), 6 test files. Modified: Customer model
(+marketing_email_opt_in fillable/cast), Cart model
(+abandoned_marketing_notified_at fillable/cast), PermissionSeeder
(+marketing.view/manage), PackageSeeder (+marketing.basic for all 3 tiers),
StoreObserver (+marketing permissions for Manager), routes/api_v1.php
(+marketing/campaign routes), routes/console.php (+scheduled command),
bootstrap/app.php (+withCommands registration fix, affecting both B4's and
B10's console commands).

Git Status:
Verified by direct execution (git status) before this checkpoint was written:
all files listed above are new/modified/staged relative to the previous commit
(e781aad / dc065c9). No files outside the Marketing domain, Customer/Cart
additive extension points, console command registration, and documentation
were touched.

Git Commit Status:
Commit created: b62c512 - "Phase B10: Marketing & Customer Engagement (Module
15)". Verified by direct execution (git log --oneline after the commit):
working tree clean, history now shows ten real commits: 5dcb815 (Phase B0-B5),
b12ae2b (B5 checkpoint correction), 47d6a1c (Phase B6), 24b7bd3 (B6 checkpoint
correction), 66d66a5 (Phase B7), 6888a16 (B7 checkpoint correction), 3184400
(Phase B8), 3259813 (B8 checkpoint correction), e781aad (Phase B9), dc065c9
(B9 checkpoint correction), b62c512 (this milestone). No fabricated
incremental history.

Recommended Next Milestone:
Phase B11 - Module 21 (Notifications & Communication), per the user's own
stated plan. B7 deferred payment notifications, B8 deferred shipment
notifications, B9 deferred promotion-redemption notifications, and B10 now
adds campaign-recipient and abandoned-cart notifications to that same deferred
list - all for the identical documented reason: "no Notification model exists
yet." Phase B11's own Step 1 should inspect every outbox event type emitted
since Phase B0 (order.created, order.cancelled, payment.initiated,
payment.refunded, shipment.created, marketing.recipient_queued,
marketing.abandoned_cart_detected, and others) before designing the
notification consumer, since wiring all of them retroactively is exactly the
kind of single-milestone consolidation this project's own checkpoint history
has been recommending since Phase B9.
