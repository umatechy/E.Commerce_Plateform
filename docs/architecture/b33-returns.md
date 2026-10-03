# B33 — Returns, inspection, refunds and replacement orders (gap G8)

Scope: Module 09 §45–54 and §65, Module 13 §70, Module 08 §46–47,
Module 12 §46–49. SRS ORD-008, PAY-011.

## 1. Model

A return is its own record beside the order. The order is never edited
(Module 09 Final Rule: orders are history); it only carries a summary.

| Table / column | Purpose |
|---|---|
| `return_requests` | One return: order, customer, number (`R-{order number}-{n}`), status, what the customer wants (`resolution`), reason code and words, who asked (customer / staff), decision note, return shipping (method, who pays, carrier, tracking), warehouse that received it, money (items, shipping refund, restocking fee, total, actually refunded, refund transaction), replacement order, timestamps of each step, idempotency key |
| `return_request_items` | One order line in the return: quantity asked; after inspection `resalable`, `damaged`, `rejected` (they add up to the quantity), note, this line's refund |
| `orders.return_status` | `none`, `return_requested`, `partially_returned`, `returned` — written only by `OrderService::syncReturnStatus()` |
| `orders.replacement_for_order_id` | A replacement/exchange order points to the order it replaces (§54) |

Why a separate `return_status`: since B8 an order's `status` does not
follow shipping (fulfilment has its own status), so the blueprint's
`delivered → return_requested` transition is never reachable. Returns
follow the same pattern as payment and fulfilment: their own status on
the order, their own records.

## 2. States (§46, the blueprint's list as it stands)

```
requested ─▶ under_review ─▶ approved ─▶ in_transit ─▶ received ─▶ inspected
    │             │             │                                     │
    └─▶ rejected ◀┘             └─▶ received (handed over directly)   ├─▶ approved_for_refund ─▶ completed
    └─▶ cancelled (until the goods are received)                      ├─▶ completed (replacement / exchange order made)
                                                                      └─▶ rejected (nothing was accepted)
```

`ReturnStateMachine` is the only place that decides a transition;
`ReturnService` the only writer.

## 3. What can be returned (`ReturnEligibility`)

- Per order line: quantity in **delivered** shipments, minus what is in a
  return that is open or was accepted. Units rejected at inspection stay
  counted (they were judged once); a rejected or cancelled *request*
  gives its units back.
- Customers: only when the store allows it
  (`returns.customer_requests_enabled`, default off) and within
  `returns.window_days` of the delivery. Staff can always record a
  return, also later.
- A cancelled order has nothing to return.
- Checked again under a lock on the order row, so two requests cannot
  both take the same unit.

## 4. Inspection and stock (§48, Module 08 §46–47)

Nothing is counted into stock when the goods arrive. At inspection:

| Result | Stock |
|---|---|
| Resalable | `RETURN_IN` +n: on hand again |
| Damaged | `RETURN_IN` +n, then `DAMAGE_OUT` −n: the trail shows it came back and was written off; it never becomes sellable. There is no separate damaged balance |
| Rejected | No movement; the units go back to the customer |

Each movement has its own idempotency key and references the return.
The stock row is the product's in the warehouse that received the goods
(created empty if the product was never stocked there).

If nothing is accepted, the return ends as `rejected`.

## 5. Money (§49–51, Module 12 §46–49)

`RefundCalculator`, integers only:

```
line paid   = line total − its share of the order-level discount + its tax
line refund = line paid × accepted ÷ ordered   (rounded down; the whole
              line returns the whole amount)
```

The screen never sends a refund amount. Staff choose two things, both
bounded on the server: a **shipping refund** (≤ what is left of the
order's shipping) and a **restocking fee** (≤ the items amount).
Approving the refund (`returns.approve`) and paying it
(`payments.refund`) are separate steps and permissions.

Payment: `PaymentService::refund()` with key `return:{id}:refund`,
under the payment's row lock; it never returns more than the payment
holds. An order that was never paid returns nothing, and the return
records what was actually refunded.

## 6. Replacement and exchange (§53–54)

`POST /returns/{id}/replacement` after inspection creates a normal order
through `OrderService` (server prices, stock reserved), linked to the
original and marked `source = replacement`:

| | Items | Charge |
|---|---|---|
| Replacement | The accepted units again | Free: the discount equals the subtotal |
| Exchange | Chosen by staff | The returned value is taken off. More expensive: the customer pays the difference by COD or bank transfer. Cheaper: the rest stays on the return and is refunded |

The new order gets its payment record at once (a free one is settled
immediately, Module 12 §14), so it can be shipped. No shipping charge.
Key `return:{id}:replacement`: made once.

## 7. API

Staff (`/api/v1`, tenant-scoped; `ReturnPolicy`):

| Route | Permission |
|---|---|
| `GET /returns`, `GET /returns/{id}`, `GET /orders/{id}/returnable` | returns.view |
| `POST /orders/{id}/returns`, `…/cancel`, `…/in-transit`, `…/receive`, `…/inspect`, `…/replacement` | returns.manage |
| `POST /returns/{id}/review`, `…/approve`, `…/reject`, `…/approve-refund` | returns.approve |
| `POST /returns/{id}/refund` | payments.refund |

Customer (`customer` guard; own orders only):
`GET /customer/orders/{id}/returnable`, `POST /customer/orders/{id}/returns`,
`GET /customer/returns/{id}`, `POST …/cancel`, `POST …/shipped`.

Roles (SystemRoles and migration for existing stores): Manager and
Administrator view, manage, approve; Order manager view, manage; Staff
view. Paying a refund stays with `payments.refund` (Administrator, Owner).

## 8. Around it

- Timeline: every step is an entry on the order's timeline.
- Audit: every staff and customer action (`return.*`).
- Outbox: `return.requested`, `.under_review`, `.approved`, `.rejected`,
  `.cancelled`, `.in_transit`, `.received`, `.refunded`.
- Email: approval and rejection to the customer; the refund is announced
  by the existing `payment.refunded` email.
- Privacy (Module 32): the customer's data export lists their returns;
  erasure removes their words and tracking number, keeps the return.
- Customer merge: returns follow their orders.

## 9. Screens

Admin: Returns (list, filters in the URL), Return (items, request, money,
progress, the next step the state and the permissions allow), Returns
card on the order with "Start a return", order badges (return status,
"Replaces"). Storefront account: Returns on the order page — request
form (when the store allows it), status, the store's message, "I have
sent the items", withdraw. Settings: the two return settings.

## 10. Not built

- Photos on a return request (§45 "Images"): there is no customer upload
  store yet (media backup is not implemented either).
- Store credit (§52: "future").
- Return labels and courier pickup booking (§70): carrier, tracking
  number and who pays are recorded; no courier is integrated (gap G9).
- Returns by guests themselves: a guest contacts the store; staff record
  the return.
- A separate damaged-stock balance (Module 08 §47): damaged units are
  written off at inspection.
- Tax: the calculator includes line tax, which is 0 until gap G3.
