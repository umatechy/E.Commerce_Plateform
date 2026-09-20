# Phase B11 — Focused Notification Security Review

Static/design-level review only — **NOT EXECUTED — DEFERRED TO VS CODE RUNTIME
VERIFICATION**.

## Regression Check — B0-B10 Capabilities Confirmed Intact

Verified by direct grep/inspection: `BelongsToTenant::store()` present;
`OrderService`/`PaymentService`/`ShipmentService`/`CampaignService` contain zero
Notification references — B11 never modified any of their business logic, only
added a call inside the pre-existing, generic `ConsumeOutboxEventJob`;
`EnsureCustomerPrincipal`/`EnsureStaffPrincipal` present and unmodified.
`ConsumeOutboxEventJob`'s existing retry/backoff/tenant-resolution code is
unchanged — confirmed by inspection that `resolveToStore()`, `$tries`, and
`backoff()` are still present exactly as before.

## Standard B11 Checklist (derived from Module 21 §44-49's Data Integrity/Security/
Anti-Abuse rules, applied with the same discipline as every prior phase's 25-30
item checklist)

| # | Item | Finding | Status |
|---|---|---|---|
| 1 | Cross-tenant notification access | `NotificationMessage`/`NotificationTemplate`/`NotificationDeliveryAttempt`/`NotificationSuppression` all use `BelongsToTenant`; cross-tenant access → 404 (dedicated test). | Reviewed — OK |
| 2 | Provider secret exposure | `Store.notification_signing_secret` is `$hidden` (defense-in-depth) AND absent from `StoreResource`'s allow-list — same double-safety convention as B7/B8's webhook secrets. | Reviewed — OK |
| 3 | Published template immutability | `NotificationTemplateService::update()` is the ONLY write path and rejects any edit once `is_published = true`. Tested explicitly. | Reviewed — OK |
| 4 | Message type explicitness | `NotificationMessage.message_type` is a required, enum-cast column set once at creation — never inferred or defaulted silently. | Reviewed — OK |
| 5 | Marketing consent/suppression bypass | `NotificationService::send()` checks `requiresMarketingConsent()` unconditionally for Marketing messages — no code path skips it. Tested (opt-out, suppression list, guest-with-no-consent-record all correctly blocked). | Reviewed — OK |
| 6 | Transactional/marketing separation | Transactional/System/Security/Administrative messages never consult consent/suppression at all (Module 21 §10, tested explicitly) — a marketing opt-out can never silently disable a mandatory order-confirmation email. | Reviewed — OK |
| 7 | Delivery idempotency | `NotificationMessage.idempotency_key`, unique per store, checked first in `NotificationService::send()`. Tested. | Reviewed — OK |
| 8 | Webhook verification (N/A — no real provider webhook exists) | B11 builds no provider-facing webhook endpoint at all (no real SMS/WhatsApp/Push provider exists to send one) — nothing to verify yet, honestly deferred rather than built insecurely. | N/A — feature deferred |
| 9 | Provider message ID uniqueness | `NotificationDeliveryAttempt.provider_message_id` is not uniquely constrained across all attempts (a real duplicate-delivery reconciliation concern, §30) — deferred alongside real provider integration itself, since only the (deterministic, always-unique) mock/local IDs from Email/In-App exist today. | Documented limitation |
| 10 | Delivery state transition validity | Every transition goes through `NotificationStateMachine::assertCanTransition()` — no controller/job writes `status` directly (verified by inspection). | Reviewed — OK |
| 11 | Cross-tenant recipient/provider mapping | `NotificationChannelResolver` returns a stateless adapter with no tenant-specific configuration at all in B11 (no per-tenant provider credentials exist yet) — there is no mapping table to cross-contaminate. | N/A — no per-tenant provider config exists yet |
| 12 | Sensitive message content retention | `NotificationDeliveryAttempt` stores only provider-facing metadata (attempt number, provider name, provider message ID, failure code/reason) — never the message body itself. | Reviewed — OK |
| 13 | Template variable injection (arbitrary execution) | `NotificationTemplateRenderer` performs pure string substitution — no `eval()`, no method/property-access syntax, no SQL/PHP/JS execution path exists anywhere in this class. Tested with an XSS-shaped variable value (HTML-escaped correctly) and a template-syntax-shaped value (not re-expanded). | Reviewed — OK |
| 14 | Unsubscribe link forgery | HMAC-SHA256 signature over `(store_id, channel, destination)`, constant-time compared (`hash_equals()`), keyed by a per-store, never-exposed secret. Tested (forged signature rejected, cross-store signature rejected). | Reviewed — OK |
| 15 | Unsubscribe requires no destructive side effect beyond intended | `UnsubscribeController` only ever creates a `NotificationSuppression` row (idempotent via `firstOrCreate` — tested) — it cannot delete data, change an order, or affect anything beyond future marketing eligibility for that one destination. | Reviewed — OK |
| 16 | Customer authorization (in-app) | `CustomerNotificationController` resolves ownership by direct identity match (`recipient_id === $customer->id`), same model as Cart/Wishlist since Phase B6 — never a route parameter trusted as proof of ownership. Tested (403/404 for another customer's message). | Reviewed — OK |
| 17 | Customer impersonation via preferences | `updatePreferences()` always operates on `$request->user()` (the authenticated principal) — no endpoint accepts a client-supplied customer id to modify a DIFFERENT customer's preference. | Reviewed — OK, N/A by construction |
| 18 | Staff authorization | `NotificationPolicy` (`view`/`manage`) — checked in every staff controller method. Tested (403 without permission). | Reviewed — OK |
| 19 | Staff access to customer preferences | No staff endpoint reads or writes `Customer.marketing_email_opt_in` in B11 — that remains customer-only, consistent with this milestone's own "do not expose customer notification preferences through staff APIs" instruction. | Reviewed — OK |
| 20 | Mass assignment | Every model uses explicit `$fillable`; `SaveTemplateRequest`/`UpdateNotificationPreferencesRequest` are explicit allow-lists. | Reviewed — OK |
| 21 | Cache isolation | No notification/template/preference data is cached anywhere in B11. | N/A this milestone |
| 22 | Queue/job tenant isolation | `DeliverNotificationJob` resolves `TenantContext` from the message row's own `store_id`, looked up by the job itself — only an internal integer id is serialized into the job payload, exactly mirroring `ProcessCampaignExecutionJob`'s (Phase B10) identical, already-reviewed pattern. | Reviewed — OK |
| 23 | API enumeration | `NotificationMessage`/`NotificationTemplate` use `public_id`/internal-staff-only ids consistently with the rest of the platform; the customer-facing in-app list only ever returns the authenticated customer's own rows. | Reviewed — OK |
| 24 | Sensitive PII leakage | `NotificationMessageResource` deliberately excludes the raw `destination` field entirely (kept out of the one shared Resource used by both staff and customer views — see that Resource's own docblock) rather than trying to conditionally hide it per-audience. | Reviewed — OK |
| 25 | Audit integrity | `NotificationDeliveryAttempt` is append-only (no `update()`/`delete()` call exists anywhere against this model — verified by inspection). | Reviewed — OK |
| 26 | Error leakage | Every controller catches domain exceptions explicitly and returns a structured message + code; the unsubscribe endpoint returns one generic message regardless of WHY a request was rejected (invalid store, forged signature, or anything else) — never confirming or denying which reason applied (Module 21 §86). | Reviewed — OK |
| 27 | Rate limiting | No dedicated rate limit was added to the unsubscribe or customer-notification endpoints beyond the platform default — consistent with every other similarly-scoped endpoint since B9/B10's own identical, already-documented limitation. | Documented limitation |
| 28 | Anti-abuse / messaging fraud | No dedicated anti-abuse layer beyond the existing marketing frequency cooldown (Phase B10, unchanged) and consent/suppression checks (this phase) — Module 21 §46's fuller anti-abuse control set is deferred alongside real provider integration, since there is no live channel yet to abuse at scale. | Documented limitation |
| 29 | System/platform notification boundary | B11 introduces no system-level notification sender or platform-context bypass — every message in B11's scope is tenant-scoped, created either by `NotificationService::send()` (always inside a resolved tenant context) or the router (which resolves tenant from the triggering event's own store_id, via the existing ConsumeOutboxEventJob mechanism). No "pretend this is a system operation" shortcut exists anywhere. | Reviewed — OK |
| 30 | Super Admin boundary | No new Super Admin surface was added or needed. | N/A this milestone |

## Issues Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

1. `NotificationStateMachine`'s initial transition map did not allow `processing ->
   retry_pending`, which would have broken the entire retry mechanism on its first
   real use. Fixed.
2. `NotificationEventRouter::handleShipmentCreated()` embedded a template token
   inside a resolved variable's own value, expecting a second substitution pass
   the renderer deliberately never performs — every shipped-order email would have
   leaked a literal, unexpanded `{{...}}` token instead of the real tracking
   number. Fixed, with regression tests added in two separate test files.

## Known Limitations (Documented, Not Hidden)

1. No dedicated rate limiting beyond the platform default on the unsubscribe or
   customer-notification endpoints (consistent with B9/B10's identical, already-
   documented limitation for similarly-scoped endpoints).
2. `NotificationDeliveryAttempt.provider_message_id` has no uniqueness constraint
   across attempts — a real concern for provider-driven duplicate-delivery
   reconciliation (Module 21 §30), deferred alongside real SMS/WhatsApp/Push
   provider integration itself, since no live provider exists yet to produce a
   genuinely colliding ID.
3. No dedicated anti-abuse/messaging-fraud control set beyond the existing
   marketing frequency cooldown and consent/suppression checks.

None of the "found and fixed" items required deleting or resetting existing B0-B10
work. No destructive database operation was performed (all 6 new/modified
migrations in B11 are additive or new-table only).
