# B33 — Security review (returns, refunds, replacement orders; gap G8)

The B29/B30 baseline (MFA, step-up, console-only emergency reset) is
unchanged. No route left a middleware group; no policy was relaxed.

## 1. Tenant isolation

| Path | Control | Test |
|---|---|---|
| Staff return and order routes | `BelongsToTenant` + binding by public id: another store's return or order is 404 | `ReturnFlowTest::test_permissions_are_separate_and_another_store_sees_nothing` |
| Return items | Each `order_item_id` must be a line of that order (looked up through the order), else 422 | `…a_unit_can_be_returned_once…` |
| Warehouse at receiving | Resolved under the tenant scope | controller |
| Customer routes | Order and return always looked up through the signed-in customer (`customer_id`), never by id alone: another customer's is 404 | `…a_customer_asks_for_their_own_return…` |

## 2. Authorization (Module 09 §65)

Four separate abilities: view, manage, approve, and pay a refund
(`payments.refund`, unchanged since B7). The screen hides steps a role
may not take; the API refuses them (403), tested per step. A customer
can only ask, withdraw (until the goods are received) and report the
parcel sent (only after approval).

## 3. Money

| Risk | Control |
|---|---|
| Refund amount chosen by the client | The API takes no refund amount. Items value is computed on the server from what was paid; the shipping refund is capped by what is left of the order's shipping; the restocking fee by the items value |
| Refunding more than was paid | `PaymentService::refund()` reads the refundable balance under a row lock (Module 12 §48); the return records what was actually refunded |
| Double refund | One refund per return: state machine (only from `approved_for_refund`), row lock, idempotency key `return:{id}:refund` (§51) |
| Returning a unit twice | Eligibility is recomputed under a lock on the order row; open and accepted returns hold their quantities |
| Refund plus replacement for the same goods | After a replacement the items value on the return is 0 (or, for a cheaper exchange, only the rest); `approve-refund` is refused when nothing is left |
| Free goods through a replacement | Only after inspection, only for accepted units, once per return (`return:{id}:replacement`), by `returns.manage`; audited with the new order number |
| Stock invented by a return | Stock moves only at inspection, by quantities that must add up to what the return holds; each movement idempotent and referenced to the return |
| Skipping steps | `ReturnStateMachine`; tested (`test_steps_cannot_be_skipped`) |

## 4. Personal data

- The customer view of a return has no staff names, warehouse or
  inspection notes; the decision note is written for the customer.
- Audit entries hold counts, amounts and statuses, not the customer's
  words.
- Data export includes the customer's returns; erasure removes their
  description and tracking number.

## 5. Abuse

- Customer requests: off by default; limited to the return period;
  rate-limited (10 per 10 minutes, own key); idempotent.
- Staff creation: 30 per minute, own key.

## 6. Residual risks

1. Refunds for cash on delivery and bank transfer are recorded, not
   sent: staff hand over or transfer the money themselves (no gateway is
   connected, gap G9). The confirmation dialog says so.
2. A replacement order counts towards the package's monthly order limit;
   when the limit is reached the API says so and the return can be
   refunded instead.
3. No photos on requests: staff judge at inspection.
