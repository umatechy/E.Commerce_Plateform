# B49 — Couriers, labels, cash on delivery and return parcels

Gap **G9** (shipping side; payments were B48), Module 13; owner decision 2
("the first Pakistan courier provider will be selected during implementation
based on actual API and sandbox availability. Do not hardcode a courier
provider into the core architecture before this verification").

Specs read before building: Module 13 (§5–6, §27–33, §41–56, §59–61,
§64–75, §84–88, §91–98, Final Rules), Module 08 §20–24 (reservations),
Module 09 §45–54 (returns), Module 12 §15, §64 (cash on delivery), Module 30
§27, owner decisions 2026-09-30 §2.

## 1. Audit first — what existed and what was reused

| Existing (B8, B33–B34) | Decision |
|---|---|
| `ShipmentService` — the only writer of shipments; state machine; tracking events; B8 webhook with HMAC and dedupe | kept as the single writer; new operations (cancel, restock, booking, tracking, cash remittance, return parcels); a shortest-path helper so a courier's skipped steps still move along allowed transitions |
| `CarrierGatewayContract` + `CarrierResolver` (store pickup, local delivery, test-only mock courier) | kept for those; real couriers get a richer contract (`CourierAdapter`: book, cancel, track, notifications, capabilities) |
| `MockCourierCarrier` webhook | now needs a signed, recent timestamp (as B48 did for payments) |
| B48 `CredentialField`, `ProviderCredentials`, merchant-account rules | reused for courier accounts; the credential form logic moved into one shared `CredentialForm` used by both |
| B47 dompdf | labels are printed with it |
| Returns (`return_carrier`, `return_tracking_number`, `markInTransit`) | a return parcel is a shipment with `direction = return`; when the courier collects it, `ReturnService::markInTransit` runs |
| `PaymentService::recordManualConfirmation` | cash a courier pays over is recorded through it — the one payment path |

**Found and fixed in the audit (Module 08 §21):** reservations of placed
orders expired after 15 minutes like a checkout hold. Any order shipped later
found no reservation and **no stock was taken off the shelf** (B8 left a
comment about it). Now stock held for a placed order stays held until it
ships or the order is cancelled; shipping without a hold takes available
stock; a part-held line takes the rest from available stock. Unpaid online
orders are still released by B48's reconciliation, which cancels them.

**Also fixed:** a cancelled shipment still counted as shipped — the order
could not be shipped again and stayed "fulfilled". Cancelled shipments now
ship nothing.

## 2. Data (migration `2029_08_01_000001_courier_shipping`)

- `courier_accounts` — a store's account with a courier: environment,
  on/off, **encrypted** credentials, masked hints, last success / problem.
- `shipments` — `direction` (outbound | return), `return_request_id`,
  `courier_account_id`, `carrier_name` ("another courier"), `carrier_reference`,
  `weight_grams`, `pieces`, `cod_amount_minor` and `cod_status`
  (none → pending → to_remit → remitted), remittance date and reference,
  `booking_status` (booking | requesting | booked | failed | unknown) and its
  error, `label_path` (private) and `label_source`, `cancelled_at`,
  `restocked_at`, tracking reads (`last_polled_at`, `next_poll_at`,
  `poll_failures`).
- `shipment_tracking_events.event_key` — a courier event is recorded once.
- Store settings (Shipping setup only): `shipping.default_weight_grams`,
  `shipping.tracking_url_template` (https, with `{tracking}`).

## 3. Which courier — the conflict reported

Leopards, TCS and PostEx were looked at for an official API guide or sandbox
that can be checked: none is published openly (each hands them to merchants;
only third-party copies and integrators were found). Following owner
decision 2, **no real courier is coded** (`CourierRegistry::REAL_COURIERS`
is empty). What is built instead:

1. the courier contract, the engine and every screen, proven end to end with
   a **Test courier** (local and test systems only, never production);
2. **Another courier** — the way Pakistani stores work today: book in the
   courier's portal, type the tracking number here; the platform prints the
   label, shows the number on the order, gives customers the store's tracking
   link, and tracks the cash on delivery.

Adding a real courier later: one adapter class + one line in the registry,
checked in that courier's sandbox with the store's (owner's) credentials.

## 4. How it works

| Step | What happens |
|---|---|
| Create shipment | carrier: own delivery, store pickup, a connected courier, or another courier; weight and pieces; **the cash to collect is the server's** (what the COD payment still lacks, less other open parcels) |
| Booking | after the shipment and its stock movements are stored, outside the transaction; one request at a time (atomic claim); refused → the reason and "Book again"; no answer → *unknown*, booked again only when a person confirms (two bookings would send two riders); nothing is sent when the warehouse address, the customer's street/city or phone is missing — the message says what to add |
| Label | the courier's own PDF when it returns one, otherwise a platform 4 × 6 label: sender, receiver, tracking number with a Code 128 barcode, cash to collect or PREPAID, order, pieces, weight; private disk, permission-checked, audited, `no-store` |
| Tracking | `shipping:track` every 5 minutes reads parcels whose turn has come (each every 15 minutes, doubling after failures up to 6 hours, stopping at delivered/returned); a signed courier notification reads at once; events are applied once each, along allowed steps |
| Delivered | cash on delivery becomes "the courier owes you" |
| Cash received | Shipments → Cash to collect → select → reference → each order's payment is recorded once (PaymentService) |
| Returned to sender | no cash; "Put back in stock" once |
| Cancel before pickup | with the courier first (refused if collected); units back on the shelf **and still held for the order** |
| Return parcel | from an approved return: a courier pickup or another courier with a return label; collected → the return is "on its way back"; it moves no stock (the inspection does) |
| Customer | courier name, tracking number, the store's tracking link, the events — never the store's courier data (a customer-only resource) |

## 5. Who may do what

| Action | Who |
|---|---|
| See shipments, courier settings | `shipments.view` |
| Create, book, cancel, labels, restock, tracking number | `shipments.fulfill` |
| Courier accounts, weight, tracking link | `shipping_config.manage`; credentials also step-up |
| Record cash received | `payments.manage` |
| Return parcels | `returns.manage` |

## 6. Screens

- **Shipping setup → Couriers**: connected couriers (status, capabilities,
  last answer / problem, connect, on/off, disconnect); a plain note on
  Leopards, TCS, PostEx and others; usual parcel weight; the tracking link
  with a live example.
- **Create shipment**: choice cards; courier name and tracking number for
  another courier; weight and pieces; "Create and book".
- **Shipment drawer**: refused / unanswered booking with what to do; print
  label; check tracking now; cancel (with confirmation); put back in stock;
  add the tracking number; cash on delivery and its status; a timeline.
- **Shipments**: All · Needs attention · Cash to collect (select, record) ·
  Return parcels.
- **Return**: Return parcel card (arrange, label, status).
- **Storefront order**: courier name, "Track your parcel", return parcels
  marked; Urdu texts.

## 7. Tests

- `CourierShippingTest` (10): account and secrets, booked → label → tracked
  → delivered → cash remitted, refused / unanswered / missing address →
  book again, cancel before and after pickup, returned to sender and restock,
  another courier and the tracking link, notifications (forged, old, valid),
  return pickup, reservations that no longer expire, permissions and
  isolation.
- `ShippingLabelPartsTest` (unit): Code 128 table and check symbol, path
  finding. `ShipmentWebhookTest` updated for the signed timestamp;
  `StepUpTest` +2 routes. Vitest `shipping.test.tsx` (6).

## 8. Not built here

- Real courier adapters (no courier verified — owner input: a courier's
  merchant API guide and sandbox credentials).
- Courier rate quotes at checkout (§38), pickup requests (§43), COD
  settlement files from couriers (§43), multi-warehouse allocation (§64–65).
- Shipping notifications to customers (outbox events exist:
  `shipment.booked`, `shipment.status_changed`, `shipment.cancelled`,
  `shipment.cod_remitted`, `shipment.return_created`) — B50.
- Guests see return parcels only through their emailed link (unchanged).
- The barcode follows the Code 128 standard table (checked by tests for
  completeness); it has not been read with a physical scanner yet.
