# B33 — Inspection findings (gap G8: returns, exchanges, refunds)

Read before building: Module 09 §15–18, §40–54, §63–65, §72–75;
Module 13 §70–72; Module 08 §46–47; Module 12 §46–49; SRS ORD-008,
PAY-011.

## 1. What existed

| Piece | State before B33 |
|---|---|
| Order statuses `return_requested`, `partially_returned`, `returned` | Enum cases only; unreachable (see 2.1) |
| Stock movement types `RETURN_IN`, `DAMAGE_OUT` | Enum cases only, never written |
| `PaymentService::refund()` | Working (B7): refundable balance under a lock, idempotent |
| Return records, eligibility, inspection, replacement orders, permissions, screens | None |

## 2. Findings and decisions (within the specs; reported)

1. **An order's `status` does not follow shipping.** Since B8 an order
   stays `confirmed` while its fulfilment status moves; the blueprint's
   `delivered → return_requested` transition can never happen. Decision:
   returns get their own summary on the order (`return_status`), like
   payment and fulfilment (§16–17 keep those separate too), and what was
   delivered is read from the shipments. `OrderStatus` and its state
   machine are untouched.
2. **Customer self-service is the store's choice** (§45 "where the store
   allows them", §42 "must be configurable"): setting
   `returns.customer_requests_enabled`, default **off**. Staff can
   always record a return.
3. **Return period**: `returns.window_days`. The settings system needs a
   positive number, so it starts at **7 days** — a starting value for
   the store to set to its own policy, not a platform rule. It binds
   customers only.
4. **Damaged goods**: there is no damaged-stock balance (Module 08 §47).
   Damaged units are received and written off in one step, so the trail
   is complete and they are never sellable.
5. **Refund amount** (§50): what the customer paid for the accepted
   units (line total, minus its share of an order-level discount, plus
   its tax). Shipping is refunded only when staff say so; a restocking
   fee only when staff enter one. Rounded down per part; the whole line
   returns the whole amount.
6. **Two steps for money**: deciding the refund (`returns.approve`) and
   paying it (`payments.refund`) are different permissions (§65).
7. **Replacement and exchange** (§53–54) are normal orders made by
   `OrderService`, linked to the original; replacement is free, exchange
   counts the returned value and settles the difference either way; no
   shipping charge on them.
8. **Resolution can change at the end**: a customer who asked for a
   replacement can be refunded instead (out of stock), and the other way
   round, after inspection.
9. **Guests**: a guest has no account to ask from; staff record the
   return (same as every other guest service).

## 3. Not built (stated, not hidden)

Photos on requests; store credit (§52, "future"); return labels and
courier pickup (no courier, gap G9); damaged-stock balance; tax share
(0 until gap G3). See `docs/architecture/b33-returns.md` §10.

## 4. Owner decisions that would change behaviour

None is blocking. Two the owner may want to set as policy later:
- the default return period for new stores (now 7 days, off by default);
- whether shipping is refunded by default for certain reasons (defective,
  wrong item). Today staff decide per return.

## 5. Not executed — environment limitation

- Emails (approval, rejection, refund): mail driver `log`.
- The customer's part in a browser (request form, "I have sent the
  items"): covered by PHP feature tests against the customer API and by
  Vitest on the storefront component; the owner journey was run in
  Chromium.
- A real gateway refund: only COD and bank transfer exist; the refund is
  recorded on the payment.
- Screen readers; browsers other than Chromium.

## 6. Note on the test run

One background run of the full PHP suite did not finish within its time
limit while the frontend tests and build ran on the same machine;
run again alone it took 205 s and passed. No test was changed for it.
