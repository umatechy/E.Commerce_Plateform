# ADR-004 — Event / Outbox Pattern

1. **ADR ID:** ADR-004
2. **Title:** Event / Outbox Pattern
3. **Status:** PROPOSED
4. **Date:** 2026-09-18
5. **Decision Owners:** Senior Software Architect / DevOps Engineer, pending Project
   Owner approval

---

## 6. Context

Idempotency, events, and webhooks are required consistently across the documentation
(idempotency mentioned in 28 of the reviewed files; events in 39; webhooks in 27),
including Module 09 (Order state transitions), Module 12 (Payment events, webhook
verification), Module 21 (Notifications), Module 27 (Marketplace webhooks), Module 31
(API webhooks, §2.18, §2.12 idempotent financial operations), and Module 32 (Audit
events). None of these documents mandate a specific package or exact Laravel pattern —
this is left to implementation.

## 7. Problem Statement

Commerce actions (e.g. "order paid") often need to reliably trigger multiple downstream
effects (inventory decrement, invoice generation, notification, webhook delivery,
audit log entry) that must not be lost if a worker crashes, and must not be duplicated
if a job retries. Without one documented pattern, different modules risk inconsistent,
unreliable event handling — particularly around the classic "database commit succeeded
but the event never got published" failure mode.

## 8. Decision

Adopt a **custom, Laravel-native transactional outbox pattern**, built on Laravel's
existing Eloquent/queue/event primitives — not an external event-sourcing package —
per the instruction to prefer a controlled custom architecture absent a strong
documented reason otherwise.

**Domain events vs. Application/Integration events:**
- **Domain events** (e.g. `OrderPaid`, `StockReserved`) are raised *inside* the same
  database transaction as the state change that caused them, and are the internal
  vocabulary services use to react to each other within the same request/job. Domain
  event types explicitly covered from Phase 2 onward include order events (created,
  paid, cancelled, refunded), inventory events (reserved, released, adjusted), payment
  events (authorized, captured, failed), and subscription/billing events (renewed,
  upgraded, downgraded, grace-period entered, suspended) — each owned by its respective
  module (09, 08, 12, 29) but flowing through this same mechanism.
- **Integration events** (anything that must leave the current process — webhook
  delivery, cross-module async side-effects, notification dispatch) are **not** fired
  directly to Laravel's queue at the moment of the domain event. Instead, they are
  written as rows into an `outbox_events` table, in the **same database transaction**
  as the business state change.

**Outbox persistence (transactional boundary):**
- MUST be transactional: writing the business row (e.g. `orders.status = 'paid'`) and
  writing the corresponding `outbox_events` row happen in one MySQL transaction. If
  either fails, both roll back — this guarantees the event is never lost and never
  fabricated relative to the state it describes.
- The `outbox_events` table stores: `id`, `store_id` (ADR-001/003), `event_type`,
  `payload` (JSON — acceptable here per ADR-003's JSON-column rule, since it is
  write-once and not queried by field value), `status`
  (`pending`/`processing`/`published`/`failed`), `attempts`, `available_at`,
  `idempotency_key`, `created_at`.

**Publication (async, outside the transaction):**
- A dedicated, lightweight scheduled dispatcher (a Laravel scheduled command running
  every few seconds, or a dedicated queue worker polling the outbox table) reads
  `pending` rows and dispatches them onto Redis-backed Laravel queues as real jobs —
  this is the point where MAY-be-asynchronous work actually becomes asynchronous.
- Each downstream consumer (webhook delivery, notification dispatch, inventory
  side-effect, audit log writer) is a separate queued job listening for its relevant
  event types, so one slow/failing consumer does not block others.

**Idempotency:** Every outbox row carries a deterministic `idempotency_key` (derived
from the source transaction, e.g. `order:{id}:paid`). Every consumer job upserts/guards
on this key (e.g. a unique constraint on a `processed_events` tracking table per
consumer) so that a retried job is a safe no-op, satisfying Module 31 §2.12's
"financial operations MUST be idempotent" requirement platform-wide, not just at the
API boundary.

**Retry strategy:** Consumer jobs use Laravel's standard queue retry/backoff
(`tries`, exponential `backoff()`), capped at a documented maximum (default: 5 attempts
with exponential backoff up to a defined ceiling, tunable per event type — e.g. webhook
delivery may retry longer than an internal cache-invalidation event).

**Failure handling / dead-letter:** Jobs that exhaust retries are moved to Laravel's
`failed_jobs` table (standard) **and** the corresponding `outbox_events.status` is set
to `failed` with the last error recorded, so failures are visible both in the
job-queue view and the business-event view (important for Module 24 Store Health
monitoring and Module 34 Support).

**Event ordering:** Ordering is guaranteed **only within a single aggregate's event
stream** (e.g. one order's events are processed in creation order, via a per-aggregate
sequence number on the outbox row and a single-consumer-per-aggregate-key queue
routing). Cross-aggregate ordering is explicitly **not** guaranteed, matching standard
distributed-systems practice, and consumers must be designed to be order-tolerant where
cross-aggregate.

**Tenant context:** Every outbox row and every derived job carries `store_id`
explicitly (ADR-001 Layer 6) — never re-resolved from ambient request state at
publish/consume time, since the outbox dispatcher and consumer workers run outside any
HTTP request context.

**What MUST be transactional vs MAY be asynchronous:**
- MUST be synchronous/transactional (inside the same DB transaction, not eventable at
  all): core state integrity checks — e.g. inventory availability check + reservation
  at checkout, payment-status write on gateway callback, order total calculation.
- MAY be asynchronous (via the outbox): webhook delivery, customer notifications
  (email/SMS/WhatsApp), analytics/reporting updates, search index updates, invoice PDF
  generation, marketplace/integration side-effects, audit-log *fan-out* (the audit
  record's own write, however, is synchronous — see Module 32).

**Events are never an authorization backdoor:** An event consumer never re-grants
access or re-derives permissions from the event payload alone. Every consumer that
performs a further action (e.g. a notification job that queries additional order
detail, a webhook job that fetches related records) re-applies the full ADR-001
tenant-scoped query path and Module 02 policy checks for that action — the event
having been raised is never treated as implicit authorization to act, and event
payloads are never used to bypass a scope or permission check that a direct request
would otherwise require.

**Replay strategy:** Outbox rows are retained (not deleted) for a documented window
(default: 90 days, tunable per event type) after successful publication, so a specific
event can be manually replayed (re-queued for a specific consumer) by an authorized
operator investigating a downstream failure, without needing to reconstruct the event
from business data. Replay re-uses the same `idempotency_key`, so a replay of an
already-processed event is guaranteed to be a safe no-op for idempotent consumers.

**Failure recovery / eventual consistency:** Consumers are designed to tolerate the
outbox's inherent eventual-consistency window (the gap between DB commit and consumer
execution) — no part of the platform assumes an integration event has already been
processed synchronously with the triggering request. Where a user-facing flow needs
to know a downstream effect's status (e.g. "has my invoice PDF been generated yet"),
the UI polls or is pushed an update (Laravel Reverb) rather than the backend blocking
the original request on the async consumer.

## 9. Detailed Implementation Rules

- No controller or service publishes directly to a Redis queue for integration events;
  it writes an outbox row inside the existing transaction and lets the dispatcher handle
  the queue hand-off.
- Payment webhook *receipt* (inbound) is handled synchronously enough to verify
  signature and acknowledge quickly (Module 12/31 requirements), but any heavy
  downstream processing triggered by that webhook goes through the same outbox pattern.
- Every event type has a documented JSON payload shape (versioned — see ADR-005's
  approach to versioning extended to event payloads) stored alongside the module that
  owns it.

## 10. Alternatives Considered

- **A — Fire-and-forget Laravel events dispatched directly to the queue at the moment
  of the domain action (no outbox table):** Rejected; if the process crashes after the
  DB commit but before the queue dispatch call completes, the event is silently lost —
  unacceptable for payment/order/inventory correctness.
- **B — External event-sourcing/outbox package (e.g. a third-party Laravel package):**
  Rejected for the initial phase per the explicit instruction to prefer a controlled
  custom architecture absent a strong documented reason; a custom outbox table is
  simple enough to build, own, and fully understand, and avoids an external dependency
  on the platform's most safety-critical mechanism.
- **C — Full event-sourcing (event log as the source of truth, state derived from
  events):** Rejected as disproportionate; the documentation describes standard CRUD-
  plus-audit-log entities (Bible §11), not an event-sourced domain model, and this
  would be a major undocumented architectural decision the review explicitly warned
  against introducing unilaterally.
- **D (Selected) — Custom transactional outbox, as decided above.**

## 11. Why Alternatives Were Not Selected

Option A fails the core reliability guarantee the documentation implicitly requires
(idempotent, non-lossy financial/commerce events). Option B is excluded by explicit
instruction absent a documented justification, which does not currently exist. Option C
would be a significant, undocumented architecture change beyond what any Module
Blueprint describes.

## 12. Security Implications

Outbound webhook payloads are signed at dispatch time (Module 31 §2.18); the outbox
table is a natural point to attach signing metadata. Sensitive payload fields are
redacted before persistence per Module 31 §2.21 ("API logs MUST redact secrets").

## 13. Multi-Tenant Implications

Every outbox row is tenant-bound (see §8, Tenant context); the dispatcher and consumer
jobs re-apply ADR-001's tenant scoping using the row's stored `store_id`, never ambient
context.

## 14. Database Implications

Adds one new foundational table, `outbox_events` (see ADR-003 conventions: `id` PK,
`store_id` FK with leading index, JSON payload column, status enum-as-string,
timestamps). A `processed_events` (or per-consumer equivalent) idempotency-tracking
table is also required.

## 15. API Implications

Webhook delivery (an integration event) is the primary API-facing consumer of this
pattern; Developer API-facing event/webhook documentation (Module 31) describes the
payloads these consumers deliver.

## 16. Testing Implications

- Transaction-rollback test: simulate a failure after the business write but before
  commit, assert no outbox row exists (i.e., true atomicity).
- Idempotency test: dispatch the same consumer job twice with the same
  `idempotency_key`, assert only one side-effect occurs.
- Retry/dead-letter test: force a consumer to fail past its retry ceiling, assert it
  lands in `failed_jobs` and `outbox_events.status = 'failed'`.
- Per-aggregate ordering test for at least the Order and Payment aggregates.

## 17. Operational Implications

The dispatcher and consumer queues need monitoring (Module 24 Store Health) — a growing
`pending`/`failed` outbox count is a first-class operational signal, not just a queue
depth metric.

## 18. Scalability Implications

The outbox table is a straightforward, indexable MySQL table; at very high event
volume it can later be partitioned or moved to a dedicated queue/broker without
changing the application-facing contract (services still just "write an outbox row in
the transaction") — Module 35 (Future Expansion Framework) compatibility is preserved.

## 19. Migration / Rollout Considerations

Not required for Milestone 0. Required starting Phase 2 (Commerce Engine), specifically
before Order/Payment/Inventory modules are implemented, since those are the first
modules with real transactional side-effects.

## 20. Consequences

**Positive:** Reliable, non-lossy, idempotent event handling with a single well-
understood pattern reused across all modules; no premature external dependency.

**Negative / trade-offs:** Requires building and maintaining the dispatcher/consumer
infrastructure in-house rather than adopting an off-the-shelf package; introduces a
small publish-latency window (the polling interval) between a transaction committing
and its integration events being dispatched — acceptable for this platform's use cases
(none require sub-second webhook delivery per the documentation).

## 21. Future Reconsideration Conditions

Reconsider if event volume or delivery-latency requirements grow beyond what a
polling-based dispatcher comfortably serves (at which point a message broker such as
Kafka/SQS could replace the dispatch mechanism without changing the outbox-write
contract), or if a specific module documents a hard sub-second delivery requirement not
currently present.

## 22. Related Project Documents

Module 09 (Order Management — state transitions), Module 12 (Payment Management —
webhook verification, idempotency), Module 21 (Notifications), Module 24 (Store
Health, Monitoring), Module 27 (Marketplace webhooks), Module 31 §2.12, §2.18–2.20
(idempotency, webhooks, async processing), Module 32 (Audit events); ADR-001 (tenant
context in jobs/events); ADR-003 (schema conventions used for the outbox table).
