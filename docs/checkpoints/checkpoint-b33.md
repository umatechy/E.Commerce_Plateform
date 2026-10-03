============================================================
PHASE B33 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B33 — Returns, inspection, refunds and replacement orders (gap G8;
Module 09 §45–54 and §65; Module 13 §70; Module 08 §46–47; Module 12
§46–49; SRS ORD-008, PAY-011)

Starting point:
v1.1 at 93701af (CI green). Refunds on a payment worked (B7). Return
statuses and the stock movement types for returns existed as enum
cases only; there was no return record, no screen, no permission.

Implementation Summary:

1. Return records (migration 2028_06_01_000001)
`return_requests` and `return_request_items`; `orders.return_status`
and `orders.replacement_for_order_id`. A return never edits its order.
Permissions returns.view / returns.manage / returns.approve, granted
to the system roles of new and existing stores; paying a refund stays
with payments.refund.

2. Rules (app/Domain/Returns)
- ReturnStateMachine: the ten states of Module 09 §46.
- ReturnEligibility: only delivered units, each once; customers only
  when the store allows it and inside the return period.
- RefundCalculator: what was paid for the accepted units (discount
  share, tax), integers only.
- ReturnService: request, review, approve, reject, cancel, sent back,
  receive, inspect, approve refund, pay refund, replacement / exchange
  order. Locks on the order and the return; idempotency keys on the
  request, each stock movement, the refund and the new order.

3. Stock and money
Inspection puts resalable units back on hand (RETURN_IN) and writes
damaged units off (RETURN_IN then DAMAGE_OUT); rejected units go back
to the customer. The refund goes through PaymentService::refund and can
never exceed what the payment holds. A replacement order is free; an
exchange counts the returned value and settles the difference.

4. APIs
Staff: /returns (list, detail, ten actions), /orders/{id}/returnable,
/orders/{id}/returns. Customer: returnable, request, show, withdraw,
"sent". Emails for approval and rejection; order timeline entries;
audit entries; outbox events.

5. Screens
Admin: Returns list and detail with the next step the state and the
permissions allow; Returns card and "Start a return" on the order;
return status and "Replaces" on orders; two return settings.
Storefront account: Returns on the order page.

6. Other modules
Privacy export lists returns, erasure removes the customer's words and
tracking number; customer merge moves returns with the orders.

Decisions (details: docs/development/b33-inspection-findings.md §2):
own `return_status` on the order because an order's status does not
follow shipping since B8; customer self-service off by default; return
period setting starting at 7 days; damaged units written off (no
damaged balance); approving and paying a refund are separate
permissions.

Reused (not duplicated):
OrderService (createOrder, timeline, status sync pattern),
InventoryService (movements, idempotency), PaymentService (refund,
createForOrder, zero-value settlement), AuditLogger, the outbox,
NotificationEventRouter, CustomerOrderHistory, the B31 UI kit, API
layer, ProductPicker, the money helpers of the currency change.

Tests (2026-10-03):
PHP: 1076 passed, 0 failed (1066 before; ReturnFlowTest 10 added; the
AdminUiTest role list and the CustomerPrivacyTest erase summary updated
for the new permission and count).
PHPStan: no errors.
Vitest: 151 passed (142 before; returns.test.tsx 9 added).
ESLint, TypeScript: clean. Production build: passes.

Browser verification — EXECUTED (Chromium via Playwright, local server,
MySQL 8.0, MFA on, production build): 7 of 7 checks.
Owner with a product at Rs. 2,500 and 20 in stock; an order of 3
delivered and paid; "Start a return" refuses 4 and takes 2; approve
with a message; receive; inspection refuses a wrong sum, then 1 good
and 1 damaged moves stock 17 → 18; refund Rs. 5,000 less a Rs. 100 fee
= Rs. 4,900 approved, then paid; the order total is unchanged, its
payment partially refunded; returning 2 more is refused (422), the last
unit is returned for a replacement and a free linked order is created
that says "Replaces"; the Returns list shows both and its filter is in
the URL; the order says "Returned". The only console error was the
deliberate 422.

NOT EXECUTED — ENVIRONMENT LIMITATION:
- Emails (approval, rejection, refund): mail driver `log`.
- The customer's part in a browser: covered by feature tests on the
  customer API and by Vitest on the storefront component.
- A real gateway refund: no gateway is connected (gap G9).
- Screen readers; browsers other than Chromium.

Known limitations:
1. No photos on a return request; no store credit (Module 09 §52,
   "future"); no return labels or courier pickup (gap G9).
2. Guests cannot ask themselves; staff record the return.
3. No damaged-stock balance: damaged units are written off.
4. The tax part of a refund is 0 until the tax engine exists (gap G3).
5. Still open from B31: variants without their own price cannot be
   ordered; no edit/delete for attributes, shipping zones and methods,
   segments; no delete for warehouses and promotions; dashboard sums
   ignore currency.

Requirements closed:
Gap G8. SRS ORD-008.

Next:
G15 (catalog depth: collections, import/export, bulk operations —
Module 05 §16, Module 06 §34–51, Module 07). G3 (tax) and G9–G10
(providers) still wait for the owner's rules and credentials.
