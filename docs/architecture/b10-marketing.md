# Phase B10 — Marketing & Customer Engagement Architecture (Module 15)

See `docs/development/b10-inspection-findings.md` for the scope decision and four
architectural decisions (minimal consent/segment foundation, boundary-only
execution, frequency control, timezone).

## The Module 10 Gap, and How B10 Resolves It Without Building Module 10

Module 15 assigns consent and segmentation ownership to Module 10 (Customer
Management), which has never been built beyond Phase B6's minimal authentication
foundation. B10 adds only the smallest foundation Module 15's own Non-Negotiable
Rule #4 ("Marketing must respect customer consent") requires:

- `Customer.marketing_email_opt_in` — the ONE consent channel implemented (opt-IN
  by default, never opt-out-by-default).
- `MarketingSegment` — computed directly from existing `Order`/`Customer` data via
  a whitelisted rule engine, not from a Module-10 group/tag system that doesn't
  exist.

## Segment Rule Engine — No Raw SQL, No Arbitrary Expressions

`MarketingSegmentService` is the ONLY place a segment rule is evaluated. Every
rule is a `{field, operator, value}` triple checked against an explicit whitelist:

- **Fields**: `total_orders_count`, `total_spent_minor`, `last_order_at`,
  `registered_at` (all computed from `Order`/`Customer`, excluding cancelled
  orders from spend/count).
- **Operators**: `>=`, `<=`, `=`, `>`, `<`.

`validateRules()` rejects anything outside this whitelist BEFORE a segment is ever
saved (tested explicitly with a SQL-injection-shaped field name). There is no code
path anywhere in this service that interpolates admin-supplied text into a query
string or evaluates an admin-supplied expression (Non-Negotiable Rules #3-4).
Multiple rules are combined with AND only — no OR/grouping ambiguity.

## Campaign Domain

`Campaign` — email-only channel (schema-ready column for future channels),
`all_customers`/`segment` audience types, optional `Promotion` reference (never
recalculates eligibility/discount — Module 14 remains fully authoritative),
full state machine (`CampaignStateMachine`, mirrors every prior phase's identical
pattern). `CampaignRecipient` — the durable, idempotent "this customer was
considered for this campaign" record, `unique(campaign_id, customer_id)`.

## Campaign Execution Is Boundary-Only, Never Real Delivery

`ProcessCampaignExecutionJob` (queued, idempotent) does exactly three things per
audience member: check consent + frequency cooldown, create a `CampaignRecipient`
row, emit an outbox event (`marketing.recipient_queued`). It never calls an email/
SMS/WhatsApp/push API — Module 21 (the next phase) owns that entirely (Module 15
Final Rule #9). Tenant context is resolved from the Campaign row's OWN `store_id`
(loaded by the job's own database lookup), never trusted from an arbitrary job
payload field — only the internal `campaignId` integer is serialized into the job.

## Frequency Control (No Numeric Value Given by Spec — Documented Decision)

One marketing action per customer per 24 hours, checked before creating a
`CampaignRecipient` row. A customer within the cooldown is recorded with status
`SkippedFrequency` (visible for staff review), not silently dropped.

## Audience Evaluation Timing (Module 15 §9 — Documented Decision)

**Execution-time evaluation**: the audience is (re)computed every time
`ProcessCampaignExecutionJob` actually runs — on initial activation AND on resume-
from-pause. A newly-matching segment member added after scheduling is still
included when the job eventually runs. This is safe to re-run because
`CampaignRecipient`'s unique constraint prevents re-queuing an already-considered
customer.

## Idempotent-Replay and Terminal-State Correctness

`CampaignService::dispatchExecution()` checks `idempotency_key !== null` before
transitioning or dispatching — a second activation call on an already-activated
campaign is a safe no-op (tested explicitly). `ProcessCampaignExecutionJob::handle()`
returns early if the freshly-loaded campaign is already in a terminal state — a
retried/duplicate job dispatch after completion is a safe no-op rather than a crash
(a real bug found and fixed during implementation — see inspection findings).

## Abandoned Cart Detection (Module 15 §33-35 — the One Concrete Trigger Built)

`AbandonedCartDetectionService`, run by a scheduled console command
(`marketing:detect-abandoned-carts`, every 15 minutes), finds `Active` carts with
items, a known `customer_id` (a guest cart has no consentable recipient), idle for
2+ hours, and not yet notified — then marks
`Cart.abandoned_marketing_notified_at` and emits `marketing.abandoned_cart_detected`.
Deliberately separate from `Cart.status`/`expires_at` (Phase B6) — the cart itself
is never altered, expired, or deleted by this detection.

## Two Pre-Existing Bugs Found During This Milestone (Not Introduced by B10)

While wiring `DetectAbandonedCarts`'s console command registration, inspection
found that **`bootstrap/app.php` never called `withCommands()`** — Laravel's
default auto-discovery only scans `app/Console/Commands`, but this codebase places
commands inside their owning domain (e.g. Phase B4's `ExpireStaleReservations`).
**This means the reservation-expiry scheduled command was never actually
discoverable by Artisan since Phase B4**, despite being scheduled in
`routes/console.php`. Fixed by adding explicit `withCommands([...])` registration
for both the pre-existing Inventory console directory and the new Marketing one.

## Entitlement Integration

`marketing.basic` feature flag (all 3 package tiers) — no numeric campaign/segment
count limit was invented (Module 15 doesn't specify one).

## API Endpoints Added in B10

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET/POST | `/api/v1/marketing/segments` | staff | |
| GET | `/api/v1/marketing/segments/{id}/preview` | staff | audience preview, read-only |
| GET/POST | `/api/v1/campaigns[/{id}]` | staff | |
| POST | `/api/v1/campaigns/{id}/activate,pause,resume,cancel` | staff | state-machine-guarded |
| GET | `/api/v1/campaigns/{id}/recipients` | staff | |

## UI

Not built in B10, matching B6-B9's own precedent — no storefront/admin frontend
exists yet in this pass.

## Deferred (see inspection findings for the full, explicit list)

SMS/WhatsApp/push channels, welcome/first-purchase/post-purchase/review-request/
win-back/birthday/product-launch/back-in-stock/price-drop/cross-sell/up-sell/
reorder campaign triggers (only abandoned-cart is built), loyalty/referral
foundations, campaign landing pages, A/B testing, personalization, AI marketing,
content approval/template versioning, revenue attribution/analytics, send windows/
batch tuning, marketing exports/data retention, monitoring dashboards, admin/
storefront UI.
