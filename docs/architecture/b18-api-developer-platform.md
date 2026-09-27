# Phase B18 — API & Developer Platform Architecture (Module 31)

See `docs/development/b18-inspection-findings.md` for the full inspection
report, the Critical Conflict resolution (Module 31 §4.4's OAuth/JWT text vs
ADR-002 and this milestone's own Section 4), and the bug found and fixed.

## The Central Decision — API Keys Only, Never OAuth/JWT

Module 31's own raw text names OAuth 2.0 and JWT/service tokens as
authentication mechanisms — but this milestone's own Section 4 gives an
unambiguous, repeated, explicit instruction citing ADR-002: Sanctum remains
canonical, JWT/OAuth are future/conditional only, and neither may be
introduced here. B18 implements exactly the one mechanism Module 31 itself
also names that doesn't conflict with this: **API keys**, hash-verified
(SHA-256), completely independent of Sanctum (a Developer Application is not
a `User`).

## Two New Credential Layers, Neither a User

`DeveloperApplication` (tenant-owned, created by staff) → `ApiKey` (belongs to
one Application; `key_prefix` for fast, non-secret lookup; `key_hash` — the
plaintext secret is generated once, returned once in the issue/rotate
response, and never stored or displayed again). `ApiKeyContext` is a
request-scoped holder deliberately separate from `$request->user()` — the
authenticated principal for a developer-API request is an `ApiKey`, never a
`User` or `Customer`.

## Deliberately Read-Only in B18

Five scopes — `products:read`, `categories:read`, `orders:read`,
`customers:read`, `inventory:read` — every one drawn from Module 31's own
listed examples. Write scopes (`orders:write`, any payment operation) are
explicitly deferred: each carries business-invariant risk (pricing, state-
machine transitions, stock movement) that no concrete Module 31 requirement
resolves precisely enough to expose safely this milestone, per Module 31's
own §41 instruction ("if any answer is unclear, do not expose the endpoint
until resolved").

## Rate Limiting Integrates B17, Doesn't Duplicate It

`api.default_rate_limit_per_minute` is a new B17 platform setting (required
adding a genuinely new `SettingType::Integer` case — an additive extension to
B17's own fixed type list, not a redesign). Laravel's real, distributed-
cache-backed `RateLimiter::for('developer_api', ...)` keys by the
authenticated `ApiKey`'s id (never bare IP, which would wrongly share one
bucket across every developer behind the same NAT).

## Webhooks Reuse the Existing Outbox — the Second Real Consumer

`WebhookEventRouter` is added BESIDE (never replacing) B11's
`NotificationEventRouter` inside the same, minimally-modified
`ConsumeOutboxEventJob::handle()` (confirmed by `git diff`: 9 insertions, 1
deletion — the method signature gaining one parameter). Only the exact
existing outbox event names are ever subscribable; no new event type is
invented for webhooks alone. `DispatchWebhookJob` HMAC-signs every payload,
checks `webhook_delivery_attempts` for a prior SUCCESSFUL delivery under the
same idempotency key before sending (replay protection), and never
serializes the signing secret into its own queue payload (re-read from the
database row inside `handle()`).

## SSRF Protection — Real, Time-of-Check, Explicitly Not Complete

`WebhookService::assertUrlIsSafe()` validates scheme (`https://` only) and
resolves the host to an IP, rejecting private/reserved/loopback ranges via
PHP's own real `FILTER_FLAG_NO_PRIV_RANGE`/`FILTER_FLAG_NO_RES_RANGE`. This is
genuine, buildable protection — but it is TIME-OF-CHECK: a DNS record could
change between subscription-creation time and actual delivery time (DNS
rebinding), a named residual risk in the security review, not hidden.

## Bug Found During Implementation

The `webhook_delivery_attempts` migration originally enforced
`unique(subscription, idempotency_key)` — which would have made it impossible
to record more than one delivery ATTEMPT (a retry) for the same underlying
event. Fixed by scoping uniqueness to `(subscription, idempotency_key,
attempt_number)`, with prior-success idempotency checked at the application
level instead — the same pattern this codebase already uses for every other
append-only ledger since Phase B7's `PaymentTransaction`.

## Metadata-Only Request Logging

`ApiRequestLog` records method/endpoint/status/duration only — never the
request/response body, never the `Authorization` header, never any query or
form field value. `LogApiRequest` middleware runs after the response is
produced (so the real status code is known) and only logs when
`ApiKeyContext` is actually set (never for a pre-authentication rejection).

## Super Admin and Staff Integration — No New Authorization Boundary

`SuperAdminDeveloperPlatformController` sits in B16's existing
`super_admin.platform` route group unchanged. Staff-facing management
(`DeveloperApplicationController`/`ApiKeyController`/`WebhookSubscriptionController`)
uses the existing `staff.principal` group and a new `DeveloperPlatformPolicy`
— `developer_platform.manage` is withheld from the default Manager role
(Owner-only), the same sensitivity precedent as `domains.manage` (B14), since
issuing an API key is a comparable external-access-granting action.

## API Endpoints Added in B18

| Method | Path | Auth |
|---|---|---|
| GET/POST | `/api/v1/developer/applications` | staff (`developer_platform.view`/`manage`) |
| POST/DELETE | `/api/v1/developer/applications/{id}/suspend\|reactivate`, `/{id}` | staff |
| GET/POST | `/api/v1/developer/applications/{id}/keys` | staff |
| DELETE/POST | `/api/v1/developer/applications/{id}/keys/{id}`, `/{id}/rotate` | staff |
| GET/POST/POST | `/api/v1/developer/applications/{id}/webhooks[/{id}/disable]` | staff |
| GET | `/api/dev/v1/products[/{id}]`, `/orders[/{id}]`, `/customers[/{id}]`, `/inventory` | API key (scoped) |
| GET/POST | `/api/v1/super-admin/developer/applications[/{id}/suspend]` | Super Admin (platform-global group) |

## UI

Not built in B18, matching every backend-focused phase's own precedent.

## Deferred (see inspection findings for the full, explicit list)

OAuth 2.0, JWT/service tokens, mTLS; write scopes (orders/customers/
inventory writes, any payment operation); Partner API/Marketplace App API
(Module 27 absent); GraphQL/gRPC; OpenAPI schema generation (no tooling
exists); sandbox/production credential separation; bulk operations; file/
media APIs; reporting/analytics API exposure; API documentation generation;
admin-facing UI.
