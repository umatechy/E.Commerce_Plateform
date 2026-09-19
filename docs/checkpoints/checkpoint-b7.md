============================================================
PHASE B7 CHECKPOINT
============================================================

Phase:
Development Phase B

Module:
B7 — Payment Management & Gateways (Module 12)

Implementation Summary:
Implemented a provider-independent payment engine on top of the unchanged Phase B5
OrderService and Phase B4 InventoryService: a Payment aggregate + append-only
PaymentTransaction ledger, a payment-level state machine distinct from Order's own
payment_status summary field, a gateway abstraction with three adapters (Cash on
Delivery, Bank Transfer, and a test-mode-only Mock Redirect Gateway standing in for
JazzCash/Easypaisa/future cards), signature-verified idempotent webhook processing,
server-authoritative payment amounts, a refund foundation with refundable-balance
validation, and staff-facing APIs. Checkout (Phase B6) now creates a Payment
immediately after creating an Order, in one transaction. Runtime execution remains
deferred to VS Code — nothing in this milestone has been executed against a real
PHP/MySQL runtime, and no live gateway credentials exist in this environment.

Bugs Found and Fixed (design-time, caught before being left in the codebase):
1. The `payment_webhook_secret` backfill migration originally used a single
   `DB::table('stores')->whereNull(...)->update(['payment_webhook_secret' =>
   Str::random(64)])` call. Because Str::random(64) is evaluated ONCE in PHP, this
   would have assigned the EXACT SAME secret to every existing store — a severe
   security defect (any one store's secret would validate webhook signatures
   intended for every other store). Fixed by looping per-row; StoreObserver updated
   so every newly created store also gets its own random secret at creation.
2. PaymentStateMachine's initial transition map did not allow created -> paid,
   which would have made the zero-value-order path (Module 12 Sec14) throw
   InvalidPaymentStateTransitionException on every zero-value order. Fixed by
   adding paid as an explicitly-commented exception to the created state.
3. Phase B6's own CheckoutTest.php and CheckoutConcurrencyTest.php would have
   broken against B7's now-required payment_method field and the checkout
   response's new nested {order, payment, redirect_url} shape. Fixed as part of
   this milestone's mandatory regression review (Step 29) — this is a documented,
   intentional API-contract evolution, not a silent break; OrderService and
   InventoryService themselves were not modified.

Architectural Decisions:
- Order is created FIRST, then Payment (OrderService performs the inventory/
  entitlement checks that must succeed before payment makes sense).
- A 2-tier Payment/PaymentTransaction model, not Module 12's suggested 3-tier
  Payment/Attempt/Transaction split — every attempt and every financial event is
  one append-only PaymentTransaction row, distinguished by type and status.
- Refunds update ONLY Order.payment_status, never Order.status or
  OrderStateMachine — Module 12 Sec50 explicitly assigns Order refund LIFECYCLE to
  Module 09 and payment refund TRANSACTIONS to Module 12; a payment refund does not
  by itself mean the order is being returned.
- PaymentWebhookEvent deliberately does not use BelongsToTenant — a webhook arrives
  before tenant identity is verified; store_id is populated only after the
  referenced Payment resolves, for audit purposes only.

Payment Domain:
Payment (aggregate, one per Order, unique(store_id, order_id)), PaymentTransaction
(append-only ledger: type, status, amount, provider reference, actor, idempotency
key), PaymentWebhookEvent (dedup by provider+external_event_id).

Payment State Machine:
PaymentStatus carries Module 12 Sec7's full 14-state list. PaymentStateMachine
centralizes every transition; PaymentService is the only caller. created->paid is
the one documented exception (zero-value orders).

Gateway Abstraction:
PaymentGatewayContract (method/initiate/verifyWebhookSignature/
translateWebhookPayload). GatewayResolver is the single PaymentMethod-to-adapter
mapping point. New providers require zero changes to OrderService/CheckoutService.

Gateway Adapters:
CashOnDeliveryGateway (no external call, staff-confirmed), BankTransferGateway (no
external call, staff-verified), MockRedirectGateway (deterministic test-mode double
for redirect+webhook gateways; HMAC-SHA256 signature scheme keyed by a per-store
secret; NO live HTTP call to any real provider is ever made).

Webhook Architecture:
No Sanctum/staff/customer middleware on the webhook route (Non-Negotiable Rule #13).
Dedup by (provider, external_event_id) checked first; Payment resolved from the
payload's own provider_payment_reference (never the URL's {provider} segment, which
is routing only); signature verified against that specific payment's store secret;
only then does TenantContext resolve and domain processing occur. Always responds
200 regardless of internal outcome (oracle-attack prevention) — the real result is
recorded on PaymentWebhookEvent.

Idempotency:
Payment creation: unique(store_id, order_id) + idempotency_key. Webhook processing:
unique(provider, external_event_id). Refunds: dedicated idempotency_key. All three
reuse the same unique-constraint-backed pattern established since Phase B2/B4/B5 —
no parallel idempotency system was built.

Order Integration:
OrderService::syncPaymentStatus() — one new, additive method; createOrder() and
cancelOrder() were NOT modified. It is the only code that writes
Order.payment_status, called exclusively by PaymentService.

Inventory Integration:
No new inventory/reservation logic. On payment failure, PaymentService calls
OrderService::cancelOrder() (Phase B5, unchanged), which itself calls
InventoryService::release() (Phase B4, unchanged) for every active reservation tied
to the order.

Refunds:
Implemented (full and partial). Payment::refundableAmountMinor() computes
paid-minus-refunded from the authoritative transaction ledger on every call, never
cached. Refund amount exceeding the refundable balance is rejected with a 422 and
the actual refundable amount. payments.refund permission required (not granted to
Manager by default — high-risk, Module 12 Sec65).

Database/Migrations:
4 new/modified migrations: stores.payment_webhook_secret (additive column,
backfilled per-row after the bug fix), payments (new table), payment_transactions
(new table), payment_webhook_events (new table). No existing table's existing
column altered, renamed, or removed. No destructive operation performed.

API Changes:
GET /api/v1/payments, GET /api/v1/payments/{payment}, GET
/api/v1/payments/{payment}/transactions, POST
/api/v1/payments/{payment}/manual-confirm, POST /api/v1/payments/{payment}/refund
(all staff-facing, under ADR-005 /api/v1/...), POST
/api/v1/payment-webhooks/{provider} (no auth). POST /api/v1/checkout (Phase B6)
extended: now requires payment_method, response gained payment/redirect_url.

UI Changes:
None — matches Phase B6's own precedent of no storefront frontend in this pass.

Security Review:
Performed (docs/security/b7-security-review.md) — this milestone's full 30-item
checklist reviewed end-to-end, plus a B0-B6 regression confirmation. 3 design-time
issues found and fixed. 3 known limitations documented (no customer-facing payment
view; no webhook-specific rate limit; no stuck-payment reconciliation job).

Tests Added:
43 new test methods across 7 Feature test files:
- tests/Feature/Payments/PaymentCreationTest.php - 9 methods
- tests/Feature/Payments/PaymentStateMachineTest.php - 6 methods
- tests/Feature/Payments/GatewayTest.php - 7 methods
- tests/Feature/Payments/WebhookTest.php - 8 methods
- tests/Feature/Payments/RefundTest.php - 6 methods
- tests/Feature/Payments/PaymentTenantIsolationTest.php - 4 methods
- tests/Feature/Payments/CheckoutPaymentIntegrationTest.php - 3 methods
Plus 1 new model factory (Payment), and 2 existing Phase B6 test files
(CheckoutTest.php, CheckoutConcurrencyTest.php) updated for the new API contract.
Combined with all carried-forward B0-B6 tests: 225 test methods total across the
whole suite (verified by direct grep count, not estimated).

Tests Actually Executed:
NONE. No PHP, Composer, MySQL, or Redis runtime is available in this Claude App
sandbox.

Tests Not Executed:
All 225 test methods, including all 43 new to this milestone.

Live Gateway Testing Status:
DEFERRED. No live JazzCash, Easypaisa, or card gateway credentials exist in this
environment. No real HTTP call was made to any external payment provider at any
point in this milestone. MockRedirectGateway is an explicitly-labeled, deterministic
test double — its webhook signature tests use a locally-computed HMAC, never a real
provider's signing key or endpoint. Real gateway integration remains a documented,
deferred item (see inspection findings "Scope Decision").

Static Inspections Performed (EXECUTED vs INSPECTED vs NOT EXECUTED vs DEFERRED, per
this milestone's explicit distinction requirement — nothing below was EXECUTED):
- Source inspection of every new/modified file against Module 12's requirements.
- A lightweight Node.js-based brace/parenthesis balance check across all new/
  modified PHP files — no mismatches found.
- Route inspection: confirmed the webhook route carries no auth middleware and the
  staff payment routes are inside the staff.principal-guarded group.
- Migration inspection: confirmed foreign keys, unique constraints
  (store_id+order_id on payments; provider+external_event_id on webhook events),
  and index coverage for the query patterns PaymentController/PaymentService
  actually use.
- Cross-reference check: confirmed OrderService::syncPaymentStatus() and
  InventoryService::release()/OrderService::cancelOrder() are called with exactly
  the signatures those Phase B4/B5 classes already expose, with zero changes to
  either class's existing methods.
- Regression re-check of Phase B6's CheckoutTest/CheckoutConcurrencyTest payloads
  and assertions against the new API contract (see "Bugs Found and Fixed" #3).

Known Limitations:
- Nothing in this milestone has been executed against a real runtime.
- No live gateway integration exists (by design, per this milestone's explicit
  prohibition on fabricating live gateway responses).
- No customer-facing payment-view endpoint (Module 12 Sec67).
- No webhook-specific rate limiting.
- No reconciliation job for payments stuck in a non-terminal state.

Deferred Functionality:
Real JazzCash/Easypaisa/card gateway API integration, payment fees/discounts/
limits, splitting one Order across multiple payment methods simultaneously,
separate authorize-then-capture customer flow, disputes/chargebacks, reconciliation
jobs, payment health monitoring/analytics, accounting integration foundation,
multi-account provider routing, payment UI. Full list with rationale in
docs/development/b7-inspection-findings.md.

Files Changed:
New: app/Domain/Payments/ (Models: Payment, PaymentTransaction, PaymentWebhookEvent,
PaymentStatus, PaymentMethod, TransactionType, TransactionStatus,
WebhookEventStatus; Gateways: PaymentGatewayContract, PaymentInitiationResult,
GatewayResolver, CashOnDeliveryGateway, BankTransferGateway, MockRedirectGateway;
Services: PaymentService, PaymentStateMachine; Policies: PaymentPolicy;
Http/{Controllers: PaymentController, PaymentWebhookController; Requests:
RefundRequest, ManualConfirmationRequest; Resources: PaymentResource,
PaymentTransactionResource}; Exceptions: 3 classes). New: 4 migrations, 1 factory
(PaymentFactory), routes/api_v1_webhooks.php, 7 test files. Modified: Order model
(payment_webhook_secret unrelated - no change), OrderService (+syncPaymentStatus,
additive), Store model (+fillable/hidden for payment_webhook_secret), StoreObserver
(+webhook secret generation, +payments permissions for Manager), CheckoutService
(+PaymentService integration), CheckoutRequest (+payment_method), CheckoutController
(+payment method handling, new response shape), AppServiceProvider
(+PaymentPolicy), PermissionSeeder (+payments.view/manage/refund), PackageSeeder
(+payment.cod/bank_transfer/online for all 3 tiers), bootstrap/app.php (+webhook
route registration), routes/api_v1.php (+payment routes), 2 Phase B6 test files
(regression fix).

Git Status:
Verified by direct execution (git status) before this checkpoint was written: all
files listed above are new/modified/staged relative to the previous commit
(24b7bd3). No files outside the Payments domain, Checkout integration points,
Order/Store/permission/entitlement extension points, and documentation were
touched.

Git Commit Status:
Commit created: 66d66a5 — "Phase B7: Payment Management & Gateways (Module 12)".
Verified by direct execution (git log --oneline after the commit): working tree
clean, history now shows four real commits: 5dcb815 (Phase B0-B5), b12ae2b (B5
checkpoint correction), 47d6a1c (Phase B6), 24b7bd3 (B6 checkpoint correction),
66d66a5 (this milestone). No fabricated incremental history.

Recommended Next Milestone:
Phase B8 — per the approved milestone map, Module 13 (Shipping & Delivery
Management) is the next core-commerce dependency: Order.fulfillment_status (Phase
B5) and Order.shipping_address_snapshot (Phase B5/B6) both already exist,
unpopulated by any real shipping logic yet, exactly mirroring how B7 found
Order.payment_status waiting for it. Phase B8's own Step 1 should inspect
OrderService's fulfillment_status handling (currently only ever set to its default)
and CheckoutService's shipping_address handling before writing any shipping code.
