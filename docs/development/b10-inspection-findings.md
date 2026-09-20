# Phase B10 — Step 1: Inspection + Scope Decision (Marketing & Customer Engagement: Module 15)

## Inspection of Existing Code

- No existing marketing/campaign/segment implementation was found anywhere in the
  repository — B10 is a clean addition.
- `Customer` (B5/B6) — has no consent, tag, group, or segment fields at all.
  Module 15 §13/§16 explicitly assign consent/segmentation OWNERSHIP to Module 10
  ("Module 10 owns customer segmentation data," "Module 10 owns consent records"),
  but **Module 10 (Customer Management) has never been built beyond the minimal
  authentication foundation Phase B6 added** — B9's own inspection findings
  already documented this gap when deferring customer-group/segment promotions.
  **B10 cannot simply "integrate with Module 10's segmentation/consent" because
  that system does not exist.** See "Architectural Decision — Minimal Consent and
  Segment Foundation" below for how this is resolved without inventing Module 10's
  full scope.
- `Order`/`OrderItem` (B5) — reused directly as the data source for segment rules
  (order count, total spend, last order date) — no duplicate reporting/analytics
  table was created.
- `Promotion`/`Coupon` (B9) — reused UNCHANGED as a campaign's optional reference;
  `Campaign` never recalculates eligibility/discount itself (Module 15 Final Rule
  #11: "Module 14 owns promotion eligibility and discount rules").
- `RecordsOutboxEvents`, `EntitlementService`, `BaseTenantPolicy`, the queued-job
  pattern (`ExpireStaleReservations`, Phase B4) — all reused unchanged for
  marketing outbox events, entitlement gating, `CampaignPolicy`, and campaign
  execution/abandoned-cart-detection jobs respectively.
- No Module 21 (Notifications & Communication) exists yet, confirmed by the user's
  own instruction that Module 21 is the NEXT phase (B11) — Module 15 Final Rule #9
  ("Module 21 owns message delivery infrastructure") is therefore honored by
  building ONLY the boundary: campaigns create `CampaignRecipient` records and
  outbox events; nothing in B10 sends an actual email/SMS/WhatsApp/push message.
- No regressions found in B0-B9 during inspection.

## Architectural Decision — Minimal Consent and Segment Foundation (Resolves the Module 10 Gap)

Since Module 15 defers consent and segmentation to a Module 10 that does not exist,
and Module 15's own Non-Negotiable Rule #4 ("Marketing must respect customer
consent") is not optional, B10 adds the SMALLEST foundation that makes this
enforceable today, without building Module 10's full customer-group/tag/multi-
channel-preference system:

- **`Customer.marketing_email_opt_in`** (new, additive boolean column, default
  `false` — opt-IN required, the safer/more conservative default, not opt-out).
  This is the ONLY consent channel B10 implements — email. SMS/WhatsApp/push
  consent are explicitly deferred (no such channels are wired to anything real yet
  since Module 21 doesn't exist either).
- **`MarketingSegment`** — dynamic, rule-based, computed directly from EXISTING
  `Order`/`Customer` data (order count, total spend, last order date, registration
  date) via a whitelisted field/operator/value query builder
  (`MarketingSegmentService`) — never raw SQL, never an arbitrary expression
  (Non-Negotiable Rules #3-4). This does not require Module 10's group/tag concept
  to exist at all.

Both are documented as intentionally minimal, reviewed extensions — not a
reimplementation of Module 10.

## Architectural Decision — Campaign Execution Is Boundary-Only, Never Real Delivery

Module 15 Step 11/Final Rule #9 is explicit that Module 21 owns delivery. B10's
`ProcessCampaignExecutionJob` (queued, idempotent) does exactly three things per
eligible customer: (1) checks consent + suppression + a frequency cooldown, (2)
creates a `CampaignRecipient` row (the durable, idempotent "this customer was
queued for this campaign" record — unique per `(campaign_id, customer_id)`), and
(3) emits an outbox event (`marketing.recipient_queued`). It never calls an email/
SMS/WhatsApp/push API — there is no such integration point to call yet. This is
not a partial implementation of delivery; it is the complete, correct scope of
what Module 15 itself assigns to this module.

## Architectural Decision — Frequency Control (Module 15 §17/§87, No Numeric Value Given)

Module 15 requires "frequency limits must be enforced at send time" (Final Rule
#19) but gives no specific number. **Decision**: a conservative, documented default
of **one marketing action per customer per 24 hours**, checked in
`ProcessCampaignExecutionJob` before creating a `CampaignRecipient` row (a customer
with a `CampaignRecipient` created in the last 24 hours across ANY campaign for
this store is skipped, recorded with status `SkippedFrequency`). This is a
reviewed, documented default — not silently invented — and is the single frequency
rule in scope; per-channel/per-campaign-type frequency configuration is deferred.

## Architectural Decision — Timezone (Module 15 §49, No Store Timezone Column Exists)

`Store` has no timezone column. Rather than adding one from this phase (out of
Tenancy's own domain), `Campaign.scheduled_at` is stored and evaluated in UTC via
server time exclusively — documented as a scope simplification. Store-specific
timezone-aware scheduling is deferred to whichever future phase adds a proper
`Store.timezone` column (Module 03's domain, not Module 15's).

## Scope Decision (Module 15 spans 108 sections — the broadest yet; same discipline as B3-B9)

**B10 implements**: `MarketingSegment` (dynamic, whitelisted-field rule engine),
`Campaign` (email-only channel, `all_customers`/`segment` audience types, optional
`Promotion`/`Coupon` reference, a full state machine), `CampaignRecipient`
(idempotent per campaign+customer), a queued, idempotent, batched execution job,
one concrete trigger (**abandoned-cart detection**, Module 15 §33-35, the module's
own most-detailed worked example), the minimal consent/frequency/suppression
enforcement described above, outbox events for the future Module 21 to consume,
and staff-facing APIs.

**Explicitly deferred** (named so nothing is silently dropped, given this module's
exceptionally large 108-section, ~30-blueprint scope):
- SMS/WhatsApp/push marketing channels and their consent/failure handling (§22-26,
  §57-59) — only email opt-in exists; no channel infrastructure exists in Module
  21 yet to send through.
- Welcome/first-purchase/post-purchase/review-request/win-back/birthday/product-
  launch/back-in-stock/price-drop/cross-sell/up-sell/reorder campaign TRIGGERS
  (§29-32, §36-44) — only abandoned-cart detection is built as the one concrete,
  fully-specified worked example (§33-35); the `Campaign` entity itself is
  generic enough that a future phase can add these as additional trigger sources
  without restructuring it, but the trigger DETECTION logic for each is not built.
- Loyalty/referral/customer-advocacy foundations (§45-47) — explicitly named as
  depending on modules that do not exist.
- Campaign landing pages, storefront marketing display (§27-28) — no storefront
  frontend exists yet (consistent with B6-B9's own precedent).
- A/B testing, experiment safety, personalization, AI marketing foundation (§64-
  67) — explicitly out of scope per this milestone's own "do not turn B10 into...
  an AI marketing system" instruction.
- Marketing content approval workflow, template versioning (§68-69) — `Campaign`
  has plain `subject`/`body` text fields; no approval state machine or version
  history table.
- Revenue attribution, campaign analytics/conversion tracking beyond a raw
  recipient count (§60-63) — Module 22's (Reports & Analytics) territory; only the
  `CampaignRecipient` ledger itself (queryable) is built as the foundation.
  Non-Negotiable "attribution must be deterministic and auditable" is honored by
  building NOTHING here rather than an ad-hoc, undefined attribution model.
- Send windows, batch-size tuning, per-channel retry/backoff policy (§50-53) — the
  execution job processes the audience in one bounded batch pass; no time-of-day
  send-window restriction.
- Marketing exports, data retention policy (§84, §91) — no export endpoint exists;
  nothing to retroactively purge.
- Marketing monitoring/analytics dashboards (§94-95) — no consumer exists yet.
- Admin/storefront UI (React components) — matches every backend-focused phase
  since B6's own precedent of no frontend built in these phases.

None of these are abandoned — each is named so Phase B11+'s own Step 1 inspection
finds this documented list.

## Bug Found and Fixed During Implementation — Pre-Existing Since Phase B4 (Not Introduced by B10)

While registering B10's new `DetectAbandonedCarts` console command, inspection
revealed that `bootstrap/app.php` never called `withCommands()` at all — Laravel's
default command auto-discovery only scans `app/Console/Commands`, but this
codebase places console commands inside their owning domain (e.g. Phase B4's
`App\Domain\Inventory\Console\ExpireStaleReservations`). **This means
`ExpireStaleReservations` was never actually discoverable by Artisan since Phase
B4**, despite being scheduled in `routes/console.php` — the scheduled command would
have failed with "command not found" the first time it actually ran. This was a
latent gap that went unnoticed until B10's own new command needed the same
registration and prompted a check. Fixed by adding
`->withCommands([app_path('Domain/Inventory/Console'), app_path('Domain/Marketing/Console')])`
to `bootstrap/app.php` — both commands are now correctly registered.

## Second Bug Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

`ProcessCampaignExecutionJob::handle()` originally had no guard against re-running
against an ALREADY-terminal (e.g. `Completed`) campaign — a retried or duplicate
job dispatch would have called `CampaignService::markCompleted()` a second time,
which itself calls `CampaignStateMachine::assertCanTransition(Completed,
Completed)` and throws `InvalidCampaignStateTransitionException`, since terminal
states have no outgoing transitions (not even to themselves). This would have
turned an idempotent-by-design retry into a hard failure. Caught while writing the
corresponding duplicate-dispatch test, before being left in the codebase — fixed
by returning early from `handle()` whenever the freshly-loaded campaign is already
in a terminal state (`CampaignStatus::isTerminal()`).

## Third Bug Found and Fixed During Implementation (Test-Design-Time, Not Post-Hoc)

`AbandonedCartDetectionTest`'s own test helper initially tried to backdate a
Cart's `updated_at` column via a plain `update(['updated_at' => ...])` call —
`updated_at` is not in `Cart::$fillable`, so this would have silently no-opped
(mass-assignment protection), leaving every test cart at its real creation
timestamp and making the "old vs recent cart" test cases meaningless. Fixed
before being left in the codebase by using `forceFill()` (bypasses the
mass-assignment restriction) combined with disabling the model instance's
`$timestamps` flag (stops Eloquent's own automatic re-touch of `updated_at` back
to "now" during `save()`).
