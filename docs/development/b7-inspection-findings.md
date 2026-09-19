# Phase B7 — Step 1: Inspection + Scope Decision (Payments: Module 12)

## Inspection of Existing Code

- `Order.payment_status` (B5) — a `PaymentStatus` enum already exists on `Order`
  (Unpaid/Pending/Authorized/Paid/PartiallyPaid/Failed/Cancelled/RefundPending/
  PartiallyRefunded/Refunded). This is the Order's OWN summary field, distinct from
  Module 12's much more detailed payment-engine state model (§7's 14-state list).
  **Decision**: keep both, synchronized one-way (Payment engine → Order summary),
  per Module 12 Final Rule #7 "Payment status and Order status are separate
  concerns." No existing enum is renamed or removed.
- `OrderStateMachine` (B5) — `TRANSITIONS` map only registered transitions through
  Delivered/Completed/Cancelled. `RefundPending`/`PartiallyRefunded`/`Refunded`
  states already existed on `OrderStatus` (B5) but had **zero registered
  transitions** — B5's own documentation explicitly named this as the extension
  point for whichever future module owns refunds. **B7 extends this map
  additively** (new transitions only, none of B5's existing entries changed).
- `OrderService::cancelOrder()` / `InventoryService::release()` (B5/B4) — reused
  UNCHANGED for the "payment failed/expired → release reservation → cancel order"
  path (Step 15). No new reservation-release logic was written.
- `CheckoutService`/`CheckoutRequest` (B6) — extended additively: checkout now
  additionally creates a `Payment` after `OrderService::createOrder()` succeeds
  (see "Payment Creation Timing" below) and requires a `payment_method` field.
  `OrderService::createOrder()` itself is NOT modified.
- `BaseTenantPolicy`, `EntitlementService`, `RecordsOutboxEvents` — reused
  unchanged for `PaymentPolicy`, gateway-availability feature flags, and payment
  outbox events respectively.
- No regressions found in B0-B6 during inspection.

## Architectural Decision — Payment Creation Timing

Module 12 §7 (Step 7) explicitly says "do not assume payment must always be created
before Order creation or vice versa... document the architectural decision."
**Decision**: Order is created FIRST (via the unchanged `OrderService::createOrder()`,
exactly as B6 already does), THEN a `Payment` is created and initiated against that
Order. Rationale: `OrderService::createOrder()` is the one place that performs
inventory reservation and entitlement checks — an Order must exist and have
successfully reserved stock before it makes sense to ask a customer to pay for it.
This also means COD/zero-payment-effort orders (Module 12 §14) never need a "phantom"
Payment record blocking on a gateway that was never going to be called.

## Architectural Decision — 2-Tier Payment Model (Simplification of Module 12's 3-Tier Suggestion)

Module 12 §8-9 separately describes "Payment Attempt" (retry tracking) and "Payment
Transaction" (immutable financial ledger with types: AUTHORIZATION/CAPTURE/SALE/
VOID/REFUND/PARTIAL_REFUND/REVERSAL/ADJUSTMENT). B7 implements **two** tables, not
three: `Payment` (the aggregate — one per Order) and `PaymentTransaction` (append-
only, every attempt AND every financial event is a transaction row, distinguished by
`type` and `status`). A failed attempt is simply a `PaymentTransaction` row with
`status = failed` — historical attempts are fully preserved (Module 12's actual
requirement) without a third table whose sole purpose would be attempt-sequencing
that the transaction ledger's own `created_at` ordering already provides. Documented
simplification, not a missing entity.

## Architectural Decision — Refunds Touch `Order.payment_status` Only, Never `Order.status`

Module 12 §50 "Order Refund Coordination" is explicit: **"Module 09 owns the Order
refund lifecycle. Module 12 owns payment refund transactions."** A payment refund
(e.g. a partial goodwill refund) does not necessarily mean the order itself is being
returned. B7's `PaymentService::refund()` therefore calls only
`OrderService::syncPaymentStatus()` (updating `Order.payment_status` to
`RefundPending`/`PartiallyRefunded`/`Refunded`) and **never** touches `Order.status`
or `OrderStateMachine`. The overall Order-status refund/return lifecycle
(`OrderStatus::ReturnRequested`/`Returned`/`PartiallyReturned` — already schema-ready
since Phase B5) remains genuinely deferred to a future Returns module, which is the
actual trigger for those states, not a payment refund in isolation. No change was
needed to `OrderStateMachine` in B7 — it is used exactly as B5 left it.

## Scope Decision (Module 12 spans 99 sections — same discipline as B3-B6)

**B7 implements**: `Payment` + `PaymentTransaction` (append-only ledger) +
`PaymentWebhookEvent` (idempotent webhook dedup), a payment-level state machine
distinct from `OrderStatus`, a provider-independent gateway abstraction
(`PaymentGatewayContract`) with three adapters — **Cash on Delivery** (§15-17, no
external calls), **Bank Transfer** (§18-19, manual staff verification), and a
**Mock Redirect Gateway** (a generic, explicitly test-mode-only stand-in for
JazzCash/Easypaisa/future card gateways' redirect+webhook shape, per this
milestone's explicit permission to "implement the adapter contract... implement
deterministic test doubles... do NOT fabricate live gateway responses") — signature-
verified webhooks, server-authoritative payment amounts, refund foundation with
refundable-balance validation, tenant-isolated per-store webhook secrets, and
staff-facing + minimal customer-facing APIs.

**Explicitly deferred** (named so nothing is silently dropped):
- **Real JazzCash/Easypaisa/card gateway API integration** (§20-22) — no live
  credentials exist in this environment, and this milestone explicitly forbids
  fabricating live gateway responses. The adapter contract and configuration
  boundary are built (`PaymentGatewayContract`, `PaymentGatewayConfig`); the actual
  HTTP calls to a real provider's API are NOT — `MockRedirectGateway` stands in for
  this shape entirely in test mode, clearly documented as such throughout.
- Payment fees/discounts (§38-39), payment limits (§37) — no numeric values are
  given by the specification; not invented.
- Multiple/partial payments toward one Order (§40-41's "Partial Payments" beyond a
  single COD/bank-transfer/mock-redirect attempt) — B7's `Payment` aggregate
  supports multiple `PaymentTransaction` attempts (retries), but splitting one
  Order's total across several DIFFERENT payment methods simultaneously is not
  built.
- Authorization-then-separate-capture as a distinct customer-facing flow (§45) — the
  state model supports `Authorized` as a status, but no adapter in B7 actually
  performs a separate capture step (COD has none; Bank Transfer and Mock Redirect
  go straight to `Paid` on confirmation).
- Disputes/chargebacks (§52), reconciliation jobs (§53-55), payment health
  monitoring/analytics (§89-90), accounting integration (§91), support tools (§93) —
  no consumer or scheduled-job infrastructure exists yet for any of these beyond
  what the append-only `PaymentTransaction` ledger already makes possible to build
  on later.
- Store-configurable provider accounts with multiple named merchant profiles per
  gateway (§34-35 "Provider Routing") — B7 gives each store exactly one
  configuration per gateway type (a single `PaymentGatewayConfig` row per
  store+gateway), not a multi-account routing table.
- Payment UI beyond what B6's minimal checkout flow needs — no dedicated
  payment-method-selection screen, redirect-handling page, or refund UI was built
  (B6 itself had no frontend either — see that phase's checkpoint).

None of these are abandoned — each is named so Phase B8+'s own Step 1 inspection
finds this documented list.

## Bug Found and Fixed During Migration Review (Design-Time, Not Post-Hoc)

The initial draft of the `payment_webhook_secret` backfill migration used
`DB::table('stores')->whereNull(...)->update(['payment_webhook_secret' =>
Str::random(64)])` — a single UPDATE statement. Because `Str::random(64)` is
evaluated ONCE in PHP before being passed to the query builder, this would have
assigned the **exact same secret to every existing store**, a severe security
defect (any one store's secret would then validate webhook signatures intended for
every other store). Caught during review before being committed — fixed by looping
over store IDs and issuing one `update()` per row, each generating its own random
value. `StoreObserver` was also updated so every NEWLY created store receives its
own random secret at creation time, not just the migration's one-time backfill.

## Second Bug Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

`PaymentStateMachine`'s initial transition map did not allow `created → paid`, which
would have made `PaymentService::createForOrder()`'s own zero-value-order path
(Module 12 §14 — "no external gateway should be called for zero-value orders," so
the Payment goes straight to Paid) throw `InvalidPaymentStateTransitionException` on
every single zero-value order. Caught while writing the corresponding test rather
than being left in the codebase — fixed by adding `paid` as an explicitly-commented
exception to the `created` state's allowed transitions.
## Regression Fix Required in Phase B6's Own Tests (Step 29 "Regression Review")

Adding `payment_method` as a required field to `CheckoutRequest`, and changing the
checkout endpoint's response shape from a flat `OrderResource` to
`{order, payment, redirect_url}`, would have broken every existing Phase B6
`CheckoutTest`/`CheckoutConcurrencyTest` assertion — not a functional regression in
the underlying commerce flow (Checkout still calls `OrderService::createOrder()`
unchanged), but a real, honest breakage of those tests' specific payloads/assertions
against the new (intentionally evolved) API contract. Caught during this milestone's
mandatory regression review, before being left broken:
1. Every `/api/v1/checkout` test payload in both files now includes
   `'payment_method' => 'cod'`.
2. Both files' entitlement setup now also seeds `payment.cod` (checkout requires a
   payment-method entitlement, not just `orders.basic`, as of B7).
3. `CheckoutTest`'s two assertions against the OLD flat response shape
   (`data.status`, `data.grand_total_minor`, `data.id`) were updated to the new
   nested shape (`data.order.status`, `data.order.grand_total_minor`,
   `data.order.id`).

This is an intentional, documented API evolution (checkout now always creates a
Payment alongside an Order, so the response correctly needs to represent both) —
not a silent, undocumented breaking change. `OrderService`/`InventoryService`
themselves were not modified.
