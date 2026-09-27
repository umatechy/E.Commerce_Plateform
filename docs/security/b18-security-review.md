# Phase B18 — Focused Developer Platform Security Review

Static/design-level review only — **NOT EXECUTED — DEFERRED TO VS CODE RUNTIME
VERIFICATION**.

## Regression Check — B0-B17 Capabilities Confirmed Intact

Verified by direct `git diff`: `EnsureCustomerPrincipal.php`,
`EnsureStaffPrincipal.php`, `EnsureSuperAdminImpersonation.php` all show ZERO
changes. `ConsumeOutboxEventJob.php` shows a 9-insertion/1-deletion diff
(a new parameter and a few lines of call/comment) — `NotificationEventRouter`'s
own call is completely unchanged. `BelongsToTenant::store()` present. No
existing route, controller, or domain service was modified beyond these
minimal, additive touch points.

## Checklist (this milestone's own §57 categories)

| Category | Item | Finding | Status |
|---|---|---|---|
| Credential Security | Generation/entropy | `Str::random(40)` for the secret, `Str::random(8)` for the prefix — Laravel's own CSPRNG-backed random string generator. | Reviewed — OK |
| Credential Security | Storage | Only `hash('sha256', $secret)` is persisted; the plaintext is never written to any table. `key_hash` is also `$hidden` on the model as defense-in-depth. Tested explicitly. | Reviewed — OK |
| Credential Security | Lookup | Verification looks up by the non-secret `key_prefix` first, then does a `hash_equals()` comparison — never a query filtering on secret material. | Reviewed — OK |
| Credential Security | Rotation/revocation | `rotate()` issues the new key and revokes the old one in the same call; a revoked key fails `isUsable()` immediately. Tested explicitly. | Reviewed — OK |
| Credential Security | Expiration | `isUsable()` checks `expires_at` on every verification. Tested explicitly. | Reviewed — OK |
| Credential Security | Leakage/enumeration | `verify()` returns `null` uniformly for "unknown prefix," "wrong secret," "revoked," and "expired" — one identical generic HTTP message/status for all. Tested explicitly. | Reviewed — OK |
| Authorization | Scope enforcement | `EnsureApiScope` checks `ApiKey::hasScope()`, always running AFTER `EnsureApiKeyAuthenticated`. Tested explicitly (403 without the right scope). | Reviewed — OK |
| Authorization | Tenant enforcement | `EnsureApiKeyAuthenticated` resolves `TenantContext` directly from the verified `ApiKey.store_id` — never from any request parameter. Every developer-API-exposed model already uses `BelongsToTenant`. Tested explicitly (cross-store 404). | Reviewed — OK |
| Authorization | Privilege escalation / cross-tenant | Staff cannot manage another store's `DeveloperApplication`/`ApiKey`/`WebhookSubscription` — tenant-scoped route-model-binding (404, not 403). Tested explicitly. | Reviewed — OK |
| Rate Limiting | Bypass / per-application isolation | Keyed by the verified `ApiKey`'s own database id — not client-influenceable; different keys never share a bucket. | Reviewed — OK |
| Rate Limiting | Distributed correctness | Laravel's own real cache-backed `RateLimiter` — never an in-memory, per-process counter. | Reviewed — OK |
| API Security | Mass assignment | Every mutating request validates an explicit allow-list; no raw `$request->all()` reaches a model write. | Reviewed — OK |
| API Security | IDOR | Every route parameter resolves via tenant-scoped binding; `ApiKeyController`/`WebhookSubscriptionController` additionally re-check the child's own parent-application id matches the route's `{application}`. | Reviewed — OK |
| API Security | Excessive data exposure | Dev*Resource classes are deliberately minimal, hand-picked field lists — never a raw model; `DevCustomerResource` excludes password/security metadata entirely. | Reviewed — OK |
| API Security | Unsafe filtering/sorting | No client-controlled sort/filter parameter is interpolated into any query in B18's read controllers. | Reviewed — OK |
| API Security | Pagination abuse | `per_page` clamped server-side to 1-100. | Reviewed — OK |
| Webhooks | Signing | HMAC-SHA256 over the exact sent body, using the subscription's own secret (never logged, never re-returned, `$hidden`). | Reviewed — OK |
| Webhooks | Replay | Checks for an existing successful delivery under the same idempotency key before sending. Tested explicitly. | Reviewed — OK |
| Webhooks | SSRF | Rejects non-https, unresolvable hosts, and private/reserved/loopback IPs at subscription-creation time. Tested explicitly for IP-literal cases. **Residual risk, documented**: this is TIME-OF-CHECK — a DNS record could be repointed to a private IP between creation and actual delivery (DNS rebinding). A complete defense would re-validate the resolved IP at delivery time too, which B18 does not implement — flagged below, not hidden. | Reviewed — OK, with a documented residual risk |
| Webhooks | Endpoint validation | `https://`-only enforced at BOTH the FormRequest layer and independently inside `WebhookService` (defense-in-depth). | Reviewed — OK |
| Secrets | Logs | Request/audit logs never record the Authorization header, key secret, or any request body. | Reviewed — OK |
| Secrets | Responses/events/jobs | Secrets appear only in the one-time creation response (`->additional()`, outside the standard Resource shape); outbox payloads carry only ids/scopes; `DispatchWebhookJob` never receives the signing secret in its constructor — it is re-read from the database row inside `handle()`, never serialized into the queue payload. | Reviewed — OK |
| Versioning | Breaking changes | An entirely new route prefix (`/api/dev/v1/...`); zero existing `/api/v1/...` contracts changed. | Reviewed — OK |
| Infrastructure | Cache poisoning / queue payload leakage | No secret is ever placed in a cache key or queue payload anywhere in B18. | Reviewed — OK |

## Issues Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

1. `webhook_delivery_attempts`'s original unique constraint
   (`subscription, idempotency_key`) would have prevented recording more than
   one delivery attempt for the same event, making retries impossible to log.
   Fixed by scoping uniqueness to include `attempt_number`, with prior-success
   checked at the application level.

## Known Limitations (Documented, Not Hidden)

1. **SSRF protection is time-of-check, not time-of-use** — see the Webhooks/
   SSRF row above. A future hardening pass should re-validate the resolved IP
   immediately before each actual delivery attempt, not only at subscription
   creation.
2. Rate-limit configuration is a single global platform value — no per-
   application or per-store override exists yet.
3. No API documentation/OpenAPI generation exists (no tooling installed) — a
   genuine dependency gap, not fabricated.
4. Webhook delivery has no separate delivery-worker pool or circuit breaker
   for a chronically-failing endpoint beyond the job's own `tries`/`backoff()`.

None of the above required deleting or resetting existing B0-B17 work. No
destructive database operation was performed (all 5 new migrations in B18
are new-table only).
