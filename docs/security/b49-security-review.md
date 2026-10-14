# B49 security review — couriers and labels

| Area | Risk | Control | Test |
|---|---|---|---|
| Courier credentials | Leak through API, logs, DB | encrypted at rest, hidden from serialisation, masked hints / "set", audit with field names only, shared `CredentialForm` rules; step-up to change | account test (not in response, not in DB column) |
| Labels (names, phones, addresses — Module 13 §56) | Read by anyone with a link, cached, crossing stores | private disk, route needs `shipments.fulfill` in the store (others 404), audited, `Cache-Control: no-store`, `nosniff` | label and permissions tests |
| Customer view | Store courier data shown to shoppers | separate `CustomerShipmentResource` (no booking error, courier reference, cash remittance, label, actions); cancelled shipments hidden | delivered test |
| Double booking | Two riders, two charges | atomic claim (one request at a time); an unanswered booking is never repeated without a person confirming | refused / unanswered test |
| Forged or replayed courier notices | False "delivered" | a notice only triggers a read from the courier; signature with the store's own account; signed timestamp window; exact-repeat dedupe; only the tracking number is stored | notification test; B8 webhook window |
| Cash on delivery | Staff typing an amount; recording cash twice | amount from the server (payment minus other parcels); remittance once per shipment under a lock, through `PaymentService`; `payments.manage` | COD test |
| Stock | Cancelled parcel leaving stock short; restocking twice | cancel puts units back and holds them for the order once per item key; restock once (`restocked_at`, idempotency keys) | cancel and RTO tests |
| Tenant isolation | Another store's parcel, label or account | tenant-scoped models; webhook resolves the shipment then its store's account | isolation test |
| Test courier in production | A no-parcel courier live | only when `config('couriers.simulator')` and not production | — |

No new dependency. Real courier APIs are not called (none verified).
