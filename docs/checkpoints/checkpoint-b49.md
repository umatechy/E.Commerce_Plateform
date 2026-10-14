============================================================
PHASE B49 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B49 — Couriers, labels, cash on delivery and return parcels (gap G9,
shipping side; SRS SHIP-007; Module 13; Module 08 §21; owner decision 2)

Owner request (2026-10-14): "yes b49 start kren in same and full passion".

Starting point:
v1.1 at 7eed02c (B48, CI green). Shipping had zones, methods, rates,
shipments with a state machine, tracking events, a test-only mock courier
with HMAC webhooks. No courier accounts, no labels, no cancel with stock
back, no tracking reads, no cash-on-delivery follow-up, return parcels only
as typed text.

Implementation Summary:
1. CourierAdapter contract with capabilities; CourierRegistry (no real
   courier — see the conflict below); Test courier for local/test systems.
2. CourierShippingService: booking outside transactions with an atomic
   claim (refused → reason + Book again; no answer → unknown, booked again
   only when a person confirms; nothing sent without warehouse address,
   customer street/city/phone), cancel with the courier first, tracking reads
   with back-off (shipping:track), signed notifications as hints.
3. ShipmentService: cancel before pickup (units back, still held for the
   order), restock after return to sender, typed tracking for another
   courier, courier events applied once along allowed steps (shortest path),
   cash on delivery from the server and its remittance through
   PaymentService, return parcels moving the return to "in transit".
4. Labels: 4 × 6 PDF (dompdf) with Code 128, cash to collect or PREPAID;
   private, permission-checked, audited, no-store; a courier's own label
   kept as it came.
5. Courier accounts (encrypted, masked; shared CredentialForm with B48),
   usual parcel weight, the store's tracking link for customers.
6. Screens: Shipping setup → Couriers; Create shipment (choice cards,
   another courier, weight, pieces); shipment drawer (booking state, print
   label, check tracking, cancel, put back in stock, tracking number, cash,
   timeline); Shipments (All / Needs attention / Cash to collect / Return
   parcels); Return parcel card; storefront order (courier, Track your
   parcel; Urdu).

Found and fixed (audit):
- Module 08 §21: reservations of placed orders expired after 15 minutes, so
  orders shipped later took no stock off the shelf. Placed orders now keep
  their hold until shipped or cancelled; shipping without a hold takes
  available stock.
- A cancelled shipment still counted as shipped (no re-shipping; order
  stayed fulfilled).
- The customer shipments endpoint used the staff resource; it now has its
  own (no courier references, booking errors or cash data).
- Accessibility: storefront payment choices had their whole explanation as
  the radio's name; now named by the title, explained by aria-describedby
  (found by the browser check).
- The B8 mock courier webhook now needs a signed, recent timestamp.

Conflict reported (not silently resolved): owner decision 2 asks to choose
the first courier from real API and sandbox availability. Leopards, TCS and
PostEx publish neither openly (merchant-only guides; only third-party
copies). No courier is coded; stores use "Another courier" (book in the
courier's portal, type the number) until a courier's merchant API guide and
sandbox credentials arrive (owner input).

Tests (2026-10-14):
PHP: 1213 passed (CourierShippingTest 10, ShippingLabelPartsTest 3 unit,
ShipmentWebhookTest updated, StepUpTest +2 routes).
PHPStan: no errors.
Vitest: 230 passed (shipping.test.tsx 6).
ESLint, TypeScript: clean. Production build: passes.

Browser verification — EXECUTED (Chromium, local server, MFA and step-up
on, production build, B46 Tax Store): 8 checks; 7 passed in the run, the
8th (booking refused without a warehouse address → Book again → booked)
ran through in the browser and failed only at the script's own database
query (an ambiguous column); the same row was then read directly: booked,
delivered, cash remitted (TC-SETTLEMENT-B49), order paid.
1. Couriers: Test courier connected (step-up), on, tracking link with
   example; secret never shown.
2. Refused without warehouse address → address added → Book again → booked.
3. Label: application/pdf, a real PDF (also read: sender, receiver,
   barcode, PKR 1,770.00 to collect, order, pieces).
4. Another courier (Leopards + number) → the customer's order page links
   to the store's tracking page.
5. Cancel before pickup: on hand 23 → 24.
6. Tracking to delivered: ready, label_created, picked_up, in_transit,
   out_for_delivery, delivered; "the courier owes you".
7. Cash to collect → reference → order paid.
8. 375 px: shipments and shipping setup fit.
Console: no errors other than the known B25 guest profile probe (401).

Not built (b49-courier-shipping.md §8): a real courier adapter, courier rate
quotes, pickup requests, courier settlement files, multi-warehouse
allocation, shipping notifications (B50).

Owner inputs needed (at the end, as agreed): the first courier (merchant
API guide and sandbox credentials).

CI: run on 342f4cd — success, verified 2026-10-14.

Next (on the owner's word): B50 — WhatsApp and SMS.
