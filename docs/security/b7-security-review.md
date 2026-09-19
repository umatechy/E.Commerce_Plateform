# Phase B7 — Focused Payment Security Review

Static/design-level review only — **NOT EXECUTED — DEFERRED TO VS CODE RUNTIME
VERIFICATION**. No live gateway credentials exist in this environment; **live
gateway verification status: DEFERRED** (no real JazzCash/Easypaisa/card API call
was ever made or claimed — `MockRedirectGateway` is a deterministic, explicitly
test-mode-only double).

## Regression Check — B0-B6 Capabilities Confirmed Intact

Verified by direct grep/inspection: `BelongsToTenant::store()` present;
`OrderService::createOrder()`/`InventoryService::reserve()`/`release()` signatures
unchanged; `EnsureCustomerPrincipal`/`EnsureStaffPrincipal` present and unmodified;
`OrderStateMachine`'s transition map contains zero refund-related entries (by
design — see architecture doc). One intentional, documented API-contract change:
`POST /api/v1/checkout` now requires `payment_method` and returns a nested
`{order, payment, redirect_url}` shape — B6's own `CheckoutTest`/
`CheckoutConcurrencyTest` were updated accordingly (see inspection findings
"Regression Fix Required").

## Standard B7 Checklist (this milestone's 30-item Step 17 list)

| # | Item | Finding | Status |
|---|---|---|---|
| 1 | Tenant isolation | `Payment`/`PaymentTransaction` use `BelongsToTenant`; cross-tenant payment access → 404 (dedicated tests). | Reviewed — OK |
| 2 | Payment IDOR | Route-model binding + global scope; no route accepts a raw internal ID (always `public_id` for API addressing). | Reviewed — OK |
| 3 | Order IDOR | Payment always resolves its own Order via the `order()` relation — no route accepts a client-supplied order ID for payment operations. | Reviewed — OK |
| 4 | Customer authorization | Customers have NO payment endpoints in B7 at all (view-only was scoped to Module 12 §67 but not built as a separate customer route in this pass — see Known Limitations) — the safest posture (no exposure) rather than an incomplete exposure. | Documented limitation, safe by omission |
| 5 | Staff authorization | `PaymentPolicy` (view/manage/refund) — `Gate::authorize()` verified present in every `PaymentController` method. `refund` intentionally NOT granted to Manager by default (Module 12 §65 "high-risk... separately controlled"). | Reviewed — OK |
| 6 | Payment amount tampering | `PaymentService::createForOrder()` derives amount exclusively from `Order.grand_total_minor` — no method accepts a caller amount for creation. Refund amount IS caller-supplied but is validated against the authoritative `refundableAmountMinor()` before acceptance. | Reviewed — OK |
| 7 | Currency tampering | Currency is copied from `Order.currency` only, never client input. | Reviewed — OK |
| 8 | Gateway reference tampering | `provider_payment_reference` is generated server-side (`MockRedirectGateway::initiate()`) or supplied only by an already-signature-verified webhook payload — never accepted from an unauthenticated client request directly. | Reviewed — OK |
| 9 | Webhook spoofing | HMAC-SHA256 signature verification, constant-time compared (`hash_equals()`), keyed by a per-store, never-exposed secret. Tested (valid/invalid/wrong-secret cases). | Reviewed — OK |
| 10 | Signature verification | Same as #9 — enforced unconditionally before any domain processing; an unsigned or badly-signed webhook never reaches `translateWebhookPayload()`. | Reviewed — OK |
| 11 | Replay attacks | `(provider, external_event_id)` uniqueness — dedup checked FIRST, before signature verification even runs again for a known event. Tested. | Reviewed — OK |
| 12 | Duplicate webhooks | Same mechanism as #11 — a duplicate delivery with the same event ID is a guaranteed no-op (only 1 `PaymentTransaction` row created regardless of delivery count). Tested. | Reviewed — OK |
| 13 | Duplicate payment initiation | `Payment` has a `unique(store_id, order_id)` constraint AND an idempotency-key check — `PaymentAlreadyExistsException` on a genuine second attempt with a different key; idempotent replay on the same key. Tested. | Reviewed — OK |
| 14 | Double charge risk | For COD/Bank Transfer, only staff-initiated `recordManualConfirmation()` can mark Paid — no webhook path exists for these methods at all (`verifyWebhookSignature()` returns `false` unconditionally). For Mock Redirect, webhook dedup (see #11) prevents a duplicate `Sale` transaction. | Reviewed — OK |
| 15 | Duplicate refund risk | Refund has its own `idempotency_key` uniqueness, checked first in `PaymentService::refund()`. Tested. | Reviewed — OK |
| 16 | State transition tampering | Every transition goes through `PaymentStateMachine::assertCanTransition()` — no controller/webhook handler writes `payment.status` directly (verified by inspection: `Payment`'s only `update(['status' => ...])` call sites are inside `PaymentService::transitionTo()`). | Reviewed — OK |
| 17 | Sensitive gateway data exposure | `PaymentResource`/`PaymentTransactionResource` never serialize `metadata`; only explicitly safe fields are ever returned. | Reviewed — OK |
| 18 | Secret leakage | `payment_webhook_secret` is `$hidden` on `Store` (defense-in-depth) AND absent from `StoreResource`'s allow-list — verified by inspection that no code path serializes it. Never logged (no `Log::` call anywhere in the Payments domain references this column). | Reviewed — OK |
| 19 | API rate limiting | `checkout` retains B6's existing `throttle:10,1`; the webhook endpoint intentionally has NO rate limit of its own in B7 (a real gateway's own retry/backoff behavior governs delivery volume) — flagged as a known limitation, not silently assumed sufficient. | Documented limitation |
| 20 | Error leakage | Every controller catches domain exceptions explicitly and returns a structured message + code; the webhook controller specifically always returns `200` regardless of internal failure reason, to avoid leaking WHY a webhook failed via HTTP status (oracle-attack prevention). | Reviewed — OK |
| 21 | Logging of sensitive information | No raw gateway payload, secret, or full webhook body is written to any `Log::` call anywhere in the Payments domain — the raw payload IS stored in `payment_webhook_events.payload`, which is an internal, staff-audit-only table (no API resource exposes it). | Reviewed — OK |
| 22 | Cache isolation | No payment data is cached in B7. | N/A this milestone |
| 23 | Queue/job isolation | No background job introduced in B7 (webhook processing is synchronous within the request). | N/A this milestone |
| 24 | Event tenant context | `payment.initiated`/`payment.refunded` outbox events (ADR-004, reused unchanged) carry `payment_id`/`order_id` — tenant context is the event's own `store_id`, set by the unmodified `RecordsOutboxEvents` mechanism. | Reviewed — OK |
| 25 | Audit integrity | `PaymentTransaction` is append-only (no `update()`/`delete()` call exists anywhere against this model — verified by inspection), carrying `actor_id` for every staff-initiated action (manual confirmation, refund) and `null` for gateway/webhook-driven ones — the distinction itself is auditable. | Reviewed — OK |
| 26 | Unauthorized refund | `payments.refund` permission required, not granted to Manager by default; tested (`test_refund_requires_permission`). | Reviewed — OK |
| 27 | Cross-tenant gateway configuration access | `payment_webhook_secret` is a column on the tenant-scoped `Store` model — never queried without an already-resolved, verified store context (webhook processing resolves it only AFTER finding the specific Payment's own `store_id`). | Reviewed — OK |
| 28 | Callback trust boundary | B7 implements webhook-based confirmation only (Module 12 §27 "Callback vs Webhook") — no browser-redirect-return endpoint exists yet that could be mistaken for authoritative confirmation; `redirectUrlFor()` only ever returns a URL for the customer's browser to visit, it does not itself confirm anything. | Reviewed — OK, N/A (no callback endpoint built) |
| 29 | Gateway response validation | `MockRedirectGateway::translateWebhookPayload()` throws `InvalidArgumentException` on an unrecognized `outcome` value rather than silently defaulting to a success/failure assumption. | Reviewed — OK |
| 30 | Provider timeout/failure handling | Not explicitly modeled in B7 (no scheduled reconciliation job exists to detect a payment stuck in `RequiresAction` past a timeout) — documented as deferred (Module 12 §61/§83), not silently assumed handled. | Documented limitation |

## Issues Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

1. **`payment_webhook_secret` backfill migration would have assigned the SAME secret
   to every existing store** (a single UPDATE statement evaluating `Str::random()`
   once) — the most significant finding this milestone. Fixed by looping per-row;
   `StoreObserver` also updated so every new store gets its own secret at creation.
2. **`PaymentStateMachine` did not allow `created → paid`**, breaking every
   zero-value-order payment. Fixed by adding the explicitly-commented exception.
3. **B6's own `CheckoutTest`/`CheckoutConcurrencyTest` would have broken** against
   B7's now-required `payment_method` field and the checkout response's new nested
   shape — fixed as part of this milestone's mandatory regression review (see
   inspection findings).

## Known Limitations (Documented, Not Hidden)

1. No dedicated customer-facing payment-view endpoint (Module 12 §67) was built —
   the safer omission (no exposure) rather than an incomplete/under-authorized one;
   flagged for a follow-up pass alongside Module 10/11's fuller customer
   self-service surface.
2. No rate limit specific to the webhook endpoint.
3. No reconciliation job for payments stuck in a non-terminal state past a timeout
   (Module 12 §61-62 "Payment Timeout / Unknown Payment State").

None of the "found and fixed" items required deleting or resetting existing B0-B6
work. No destructive database operation was performed (all 4 new/modified
migrations in B7 are additive or new-table only).
