# Phase B18 — Step 1: Inspection + Scope Decision (API & Developer Platform: Module 31)

## Critical Conflict Identified and Resolved — Module 31 §4.4 vs ADR-002/This Milestone's Own Section 4

Module 31 §4.4 ("API Technology Foundation — Authentication") itself lists
**"OAuth 2.0 for application/user authorization"** and **"JWT/service tokens
for controlled internal or service contexts"** as two of its four named
authentication mechanisms. This directly conflicts with **ADR-002**
("Sanctum is the current canonical authentication mechanism. JWT/OAuth are
future/conditional only") and with **this milestone's own Section 4**, which
states — in the same document assigning this task — an unambiguous,
repeated, explicit instruction: *"DO NOT introduce JWT. DO NOT introduce
OAuth. DO NOT create JWT token tables. DO NOT create OAuth authorization
servers. DO NOT add speculative OAuth scopes... Do not substitute JWT/OAuth
merely because they are common API technologies."*

Per the Source of Truth Hierarchy this milestone itself provides (Module 31
at position 4, ADR-005 at position 5, "Other approved ADRs" — which includes
ADR-002 — at position 6), Module 31's raw text would nominally outrank
ADR-002. However, this milestone's OWN Section 4 has already performed the
required conflict resolution *for this exact task*, citing ADR-002 by name
and giving an unambiguous, non-negotiable directive. Per this milestone's own
meta-instruction ("if sources conflict... document the decision, do not
silently invent a compromise"), that decision is: **implement ONLY the "API
keys for approved server-to-server integrations" mechanism Module 31 itself
also names** (one of its four listed options, and the one Section 4
explicitly endorses: *"If the documentation specifies API keys: implement
secure API keys"*) — never OAuth 2.0, never a JWT/service-token system,
never speculative scopes tied to either. This is not ignoring Module 31; it
is implementing the one piece of Module 31's own architecture that is
consistent with the platform's already-established, ADR-governed
authentication boundary.

## Inspection of Existing API Architecture

- `/api/v1/...` (staff, authenticated via Sanctum), `/api/v1/public/...`
  (unauthenticated storefront data), `/api/v1/customer/...` (Sanctum,
  customer guard) all exist and are extensive (B1-B17). **No
  `/api/dev/v1/...` route file or namespace exists anywhere in this
  repository** — this is the genuine gap B18 fills.
- Every domain service B18 could plausibly expose already exists and is
  fully authoritative: `Product`/`Category`/`Brand` (B3), `OrderService` (B5),
  `InventoryService` (B4), `Customer` (B6). B18 reuses every one of these
  read-paths directly — no new business logic, no new pricing/state-machine
  code.
- `RecordsOutboxEvents`/`ConsumeOutboxEventJob` (B0, first real consumer in
  B11) — reused unchanged as the webhook trigger source; B18 adds no second
  event bus.
- B17's `ConfigService`/`SettingRegistry` — reused for one new platform
  setting (`api.default_rate_limit_per_minute`), per this milestone's own
  explicit instruction to integrate with B17 rather than inventing a
  separate rate-limit configuration mechanism.
- No regressions found in B0-B17 during inspection.

## Architectural Decision — Scope: Read-Only Developer API in B18

Module 31 §41 itself instructs: *"For every developer endpoint, answer: who
owns the credential, which tenant, which scope, which policy, which domain
service, what audit, what rate limit, what sensitive fields... if any answer
is unclear, do not expose the endpoint until resolved."* Order creation,
payment initiation, and inventory writes each carry business-invariant risk
(pricing, state-machine transitions, stock movement) that no concrete Module
31 scope/permission list resolves precisely enough to expose safely in this
milestone. **Decision**: B18 implements exactly five READ scopes —
`products:read`, `categories:read`, `orders:read`, `customers:read`,
`inventory:read` — every one drawn from Module 31's own listed scope
examples (§15), all read-only. Write scopes (`orders:write`, etc.) are
explicitly deferred, not silently narrowed without explanation — see
Deferred section below.

## Architectural Decision — API Key Design (Module 31 §9-10/§44, Non-Negotiable)

A key has a non-secret `key_prefix` (e.g. `utk_live_XXXXXXXX`, used for fast,
non-secret lookup) and a `key_hash` (SHA-256 of the FULL secret) — the
plaintext secret is generated once, shown exactly once in the creation
response, and never stored or displayed again (mirrors this platform's own
established "generate once, show once" precedent — e.g. Sanctum's own
`createToken()` return value). Verification is a hash comparison
(`hash_equals(hash('sha256', $providedSecret), $storedHash)`), never a
database query against secret material itself, and never a Sanctum personal-
access-token row (a deliberately separate credential type from `User`/staff
authentication, since a Developer Application is not a `User` at all).

## Architectural Decision — Webhooks Reuse the Existing Outbox, No New Event Bus

`WebhookSubscription` (tenant-owned: url, signing secret, subscribed event
types, status) + `WebhookDeliveryAttempt` (append-only ledger, same 2-in-1
pattern as every ledger since B7's `PaymentTransaction`). `DispatchWebhookJob`
is triggered from the SAME `NotificationEventRouter`-adjacent point
`ConsumeOutboxEventJob` already calls (B11's own established "first real
consumer" pattern) — B18 adds one more router (`WebhookEventRouter`) beside
B11's `NotificationEventRouter`, both called from the same unchanged
`ConsumeOutboxEventJob::handle()`, never a second, competing event-processing
pipeline. Only the EXACT existing event names (`order.created`, etc.) are
ever subscribable — no new outbox event type is invented for webhooks alone.

## Architectural Decision — SSRF Protection Is Real, Not Fake, But Documented as Best-Effort

At webhook-subscription-creation time, the URL's resolved IP is checked
against private/loopback/link-local/reserved ranges
(`filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE |
FILTER_FLAG_NO_RES_RANGE)`) — genuine, buildable, non-fake protection using
PHP's own real IP-validation flags. This is explicitly documented as
**time-of-check** protection, not a complete SSRF defense (DNS rebinding
between check-time and actual delivery-time is a known, named residual risk
in the security review) — consistent with this milestone's own "do not
invent an incomplete SSRF protection system without documenting limitations."

## Scope Decision Summary

**B18 implements**: `/api/dev/v1/...` route namespace, `DeveloperApplication`
+ `ApiKey` (create/rotate/revoke lifecycle, hash-verified, never OAuth/JWT),
5 read-only scopes, Sanctum-independent API-key authentication guard
middleware, tenant-safe rate limiting (per-API-key, reusing Laravel's real
distributed cache-backed throttle mechanism, configured via a new B17
setting), an append-only `ApiRequestLog` (metadata only — method/endpoint/
status/latency, never request/response bodies, never headers), webhook
subscriptions + signed, replay-resistant delivery reusing the existing
outbox unchanged, Super-Admin oversight (reusing B16's platform-global group)
+ staff-facing (store-scope) application/key management APIs.

**Explicitly deferred** (named so nothing is silently dropped):
- **OAuth 2.0, JWT/service tokens, mTLS** (Module 31 §4.4's other three
  listed mechanisms) — see the Critical Conflict section above; ADR-002
  remains authoritative.
- **Write scopes** (`orders:write`, `customers:write`, `inventory:write`,
  any payment operation) — see Architectural Decision above; each carries
  business-invariant risk this milestone does not resolve precisely enough
  to expose safely.
- **Partner API, Marketplace App API** (§3.5-3.6) — no reseller/partner
  program or app-marketplace (Module 27) exists anywhere in this codebase.
- **GraphQL, gRPC** (§4.5) — explicitly named as future/conditional by
  Module 31 itself.
- **OpenAPI schema generation** (§2.24/§48) — no OpenAPI tooling exists in
  this Laravel installation; documented as a real dependency gap, not
  fabricated.
- **Sandbox/production credential separation** (§2.25-26) — no sandbox
  environment concept exists anywhere in this platform's architecture yet.
- **Bulk operations, file/media APIs, reporting/analytics API exposure**
  (§61-62/§67) — each would need its own dedicated risk analysis; none is
  built this milestone.
- **API documentation generation** — deferred alongside OpenAPI.
- Admin/customer-facing UI (React components) — matches every backend-
  focused phase's own precedent.

None of these are abandoned — each is named so Phase B19+'s own Step 1
inspection finds this documented list.

## Bug Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

The `webhook_delivery_attempts` migration originally enforced
`unique(webhook_subscription_id, idempotency_key)` — but a webhook delivery
can legitimately be RETRIED (attempt 1 fails, attempt 2 succeeds), and both
attempts share the same idempotency key (identifying the same underlying
event delivery) while needing to be recorded as separate ledger rows. The
original constraint would have made it impossible to log a second attempt at
all. Caught before being left in the codebase — fixed by scoping the unique
constraint to `(subscription, idempotency_key, attempt_number)` instead, with
prior-success idempotency checked at the application level (query for an
existing successful row before dispatching another attempt) — the same
pattern this codebase already uses for every other append-only ledger since
Phase B7's `PaymentTransaction`.
