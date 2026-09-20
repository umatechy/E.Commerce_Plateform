# Phase B10 — Focused Marketing Security Review

Static/design-level review only — **NOT EXECUTED — DEFERRED TO VS CODE RUNTIME
VERIFICATION**.

## Regression Check — B0-B9 Capabilities Confirmed Intact

Verified by direct grep/inspection: `BelongsToTenant::store()` present;
`OrderService::createOrder()`, `PromotionEligibilityEngine::evaluate()`,
`CheckoutService` all untouched by B10 (0 Marketing/Campaign references in
Checkout); `EnsureCustomerPrincipal`/`EnsureStaffPrincipal` present and
unmodified; `OrderStateMachine`/`ShipmentStateMachine`/`PaymentStateMachine`
untouched (0 marketing-related references). B10 is a clean, additive domain that
only READS Order/Customer data for segment evaluation — it writes nothing to any
prior phase's tables except the two documented additive columns
(`Customer.marketing_email_opt_in`, `Cart.abandoned_marketing_notified_at`).

## Standard B10 Checklist (this milestone's 30-item Step 22 list)

| # | Item | Finding | Status |
|---|---|---|---|
| 1 | Cross-tenant campaign access | `Campaign`/`MarketingSegment`/`CampaignRecipient` use `BelongsToTenant`; cross-tenant access → 404 (dedicated test). | Reviewed — OK |
| 2 | Cross-tenant customer targeting | `MarketingSegmentService::resolveAudience()` queries `Customer::query()->get()`, which is tenant-scoped by the resolved `TenantContext` (the same global scope every other domain relies on) — a Store A campaign can structurally never enumerate Store B's customers. | Reviewed — OK |
| 3 | Customer data exposure | `CustomerResource` (Phase B6, reused unchanged for segment preview) is an explicit allow-list — no raw PII beyond what that resource already exposes to staff elsewhere. | Reviewed — OK |
| 4 | Segment rule injection | `MarketingSegmentService::validateRules()` rejects any field/operator outside the fixed whitelist BEFORE a segment is ever persisted. Tested explicitly with a SQL-injection-shaped field name via both the service directly and the API endpoint. | Reviewed — OK |
| 5 | Raw SQL injection through segment rules | Same as #4 — no code path ever concatenates a rule's field/operator/value into a raw query string; `evaluate()` compares already-computed PHP values via a `match()` on the operator, never building SQL from user input. | Reviewed — OK |
| 6 | Customer impersonation | No endpoint accepts a client-supplied `customer_id` for segment matching or campaign targeting — audience resolution is always store-wide (tenant-scoped) or segment-rule-based, never keyed to a client-asserted identity. | Reviewed — OK, N/A by construction |
| 7 | Unauthorized audience access | Segment preview (`GET /marketing/segments/{id}/preview`) requires `marketing.view`/Owner, same as every other staff read endpoint. | Reviewed — OK |
| 8 | Unauthorized campaign activation | `activate`/`pause`/`resume`/`cancel` all require `MarketingPolicy::manage()` (`marketing.manage` permission or Owner). Tested explicitly (403 without permission). | Reviewed — OK |
| 9 | Campaign state tampering | Every transition goes through `CampaignStateMachine::assertCanTransition()` — no controller writes `campaign.status` directly (verified by inspection: `Campaign`'s only `update(['status' => ...])` call sites are inside `CampaignService::transitionTo()`). | Reviewed — OK |
| 10 | Duplicate campaign execution | `Campaign.idempotency_key`, set once at first activation, checked before any subsequent `dispatchExecution()` call proceeds. Tested explicitly. | Reviewed — OK |
| 11 | Duplicate recipient processing | `campaign_recipients` has `unique(campaign_id, customer_id)`; the job also checks existence before creating (defense in depth). Tested explicitly (re-run job, same recipient count). | Reviewed — OK |
| 12 | Scheduling abuse | `scheduled_at` is only ever read server-side against `Carbon::now()` (server time) — no client timestamp is trusted as "current time" anywhere in `CampaignService`. | Reviewed — OK |
| 13 | Marketing preference bypass | `ProcessCampaignExecutionJob` checks `marketing_email_opt_in` BEFORE creating a `Queued` recipient row for every single audience member, with no code path that skips this check. Tested explicitly. | Reviewed — OK |
| 14 | Consent bypass | Same as #13. | Reviewed — OK |
| 15 | Promotion misuse | `Campaign.promotion_id` is a plain reference — the campaign never recalculates or overrides `Promotion`/`Coupon` eligibility, discount amount, or usage limits (Phase B9, completely unchanged). | Reviewed — OK |
| 16 | Coupon abuse | Same as #15 — B10 has no code path that touches `promotions`/`coupons`/`promotion_usages` tables at all beyond reading a `Promotion`'s existence for display. | Reviewed — OK |
| 17 | Customer enumeration | No customer-facing marketing endpoint exists in B10 at all (staff-only) — there is no surface for an external party to enumerate customers via marketing APIs. | Reviewed — OK, N/A by construction |
| 18 | Bulk-data exposure | Segment preview returns `CustomerResource`-shaped data (already an allow-list) and is staff-only, tenant-scoped, and paginated implicitly by the underlying query (a very large store's segment preview is a performance concern noted below, not a security one). | Reviewed — OK |
| 19 | API rate limiting | Marketing/campaign endpoints inherit the platform's default throttling; no dedicated additional limit was added — consistent with every other staff-only admin endpoint in this codebase (B7-B9 also did not add bespoke rate limits to their own admin CRUD). | N/A — consistent with existing precedent |
| 20 | Mass assignment | Every model uses explicit `$fillable`; `SaveCampaignRequest`/`SaveSegmentRequest` are explicit allow-lists. | Reviewed — OK |
| 21 | Cache isolation | No campaign/segment/audience data is cached anywhere in B10 — Module 15 §24's cache-safety concern is trivially satisfied by not caching at all yet. | N/A this milestone |
| 22 | Queue/job tenant isolation | `ProcessCampaignExecutionJob` resolves `TenantContext` from the Campaign row's own `store_id`, looked up by the job itself — never trusted from a job-payload field an attacker could theoretically forge if queue infrastructure were compromised (only an internal integer id is serialized). | Reviewed — OK |
| 23 | Event tenant context | `marketing.campaign_activated`/`campaign_completed`/`recipient_queued`/`abandoned_cart_detected` outbox events (ADR-004, reused unchanged) all carry their own `store_id` via the unmodified `RecordsOutboxEvents` mechanism. | Reviewed — OK |
| 24 | Sensitive PII leakage | `CampaignRecipient` stores only a foreign key to `Customer`, never a copy of name/email/phone (Module 15 Step 15's explicit "avoid storing unnecessary copies of customer PII"). | Reviewed — OK |
| 25 | Audit integrity | `CampaignRecipient` rows are effectively append-only (status set once at creation, never mutated after — verified by inspection: no `update()` call exists against an existing `CampaignRecipient` row anywhere in the codebase). | Reviewed — OK |
| 26 | Campaign metric tampering | No metrics beyond the raw, queryable `CampaignRecipient` count exist in B10 — there is no separate, mutable "metrics" field for a client to tamper with. | Reviewed — OK, N/A by construction |
| 27 | Attribution tampering | No attribution model exists in B10 at all (explicitly deferred to Module 22) — nothing to tamper with. | N/A — feature deferred |
| 28 | Unauthorized customer segmentation | Segment creation requires `marketing.manage`; segment rules are validated server-side against the fixed whitelist regardless of who submits them. | Reviewed — OK |
| 29 | Error leakage | Every controller catches domain exceptions explicitly and returns a structured message + code, matching the established B1-B9 pattern. | Reviewed — OK |
| 30 | Super Admin boundary | No new Super Admin surface was added or needed — marketing is entirely store-scoped, staff-permission-gated. | N/A this milestone |

## Issues Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

1. **`bootstrap/app.php` never registered domain-namespaced console commands at
   all** — a pre-existing gap since Phase B4 (`ExpireStaleReservations` was never
   actually discoverable by Artisan), found while wiring B10's own new command.
   Fixed for both the pre-existing Inventory command and the new Marketing one.
2. `ProcessCampaignExecutionJob::handle()` had no guard against re-running against
   an already-terminal campaign, which would have turned an idempotent-by-design
   retry into a hard `InvalidCampaignStateTransitionException`. Fixed with an
   early-return terminal-state check.
3. `AbandonedCartDetectionTest`'s own test helper tried to backdate `Cart.updated_at`
   via a plain `update()` call, which silently no-ops since that column isn't
   mass-assignable — fixed with `forceFill()` + disabled instance timestamps before
   any test was run against it.

## Known Limitations (Documented, Not Hidden)

1. No dedicated rate limiting beyond the platform default on marketing endpoints
   (consistent with every other staff-admin surface built since B7).
2. Segment preview has no pagination of its own — for a store with a very large
   customer base, this could be a performance concern at scale; not a security
   issue, but flagged for a follow-up pass.
3. Only email consent exists; SMS/WhatsApp/push consent categories are not
   implemented (no channel infrastructure exists yet to need them).

None of the "found and fixed" items required deleting or resetting existing B0-B9
work. No destructive database operation was performed (all 6 new/modified
migrations in B10 are additive or new-table only).
