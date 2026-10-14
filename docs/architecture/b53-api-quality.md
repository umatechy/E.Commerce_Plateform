# B53 — API quality (gap G13)

Sources:
- SRS API-007, API-008, API-010, API-012, TEST-009.
- Master Development Prompt §15 (API error standard) and §16 (idempotency).
- Module 31: §2.23–2.24, §35 (event catalog), §40–42 (responses, status codes, idempotency), §51 (documentation), §60 (retries), §65–66 (CI, contract and end-to-end tests).

## 1. One error shape (API-010)

`NormalizeApiErrors` (global middleware, `/api/*` only) gives every error answer:

```json
{ "code": "validation_failed", "message": "Some of the information is not valid.",
  "details": { "fields": { "email": ["The email field is required."] } },
  "requestId": "5b0f6a3e-…" }
```

- **code** is a stable, lowercase snake_case code. A code a controller already sets is kept (for example `store_closed`, `insufficient_scope`, `platform_role_forbidden`). Otherwise it comes from the status: `unauthenticated`, `forbidden`, `not_found`, `conflict`, `locked`, `too_many_requests`, `server_error` … A 422 with field errors is `validation_failed`.
- **details** is endpoint-specific. Field errors go under `details.fields`. When there is nothing to add, it is an empty object.
- **requestId** equals the `X-Request-Id` header (API-011).
- **Kept as they were:** `errors`, `message`, `code` and endpoint extras such as `capability` and `reason`. The admin and storefront apps read these, so nothing changed for them.
- **Never shown** outside a local debug run: `exception`, `file`, `line` and `trace`. A 5xx always gets a generic message, so no SQL, paths or secrets leak (§40.3).
- Unhandled exceptions under `/api` are always answered as JSON (`shouldRenderJsonWhen`).

The codes are lowercase snake_case rather than the prompt's UPPER_CASE example ("similar to"): every code the platform already gives is lowercase. Clients compare codes, not case.

## 2. OpenAPI (API-012; §51, §65)

- `DeveloperApiSpec` builds an **OpenAPI 3.1** description of the developer API (`/api/dev/v1`) from the routes themselves plus its documentation table. It covers:
  - every operation, with summary, scope (`x-scope`), parameters, paging, and the response schema;
  - the error responses (401, 403, 404, 423, 429, 503);
  - the error schema and the security scheme;
  - the webhook events.
- A developer API route without documentation stops the build, and so the test.
- `php artisan api:openapi` writes `docs/api/openapi-developer-v1.json`. CI runs `api:openapi --check` (the committed file must match the code) and Redocly lint 2.63.0 (`redocly.yaml`). The licence rule is off: choosing the licence is the owner's decision.
- The file is also served at `GET /api/dev/v1/openapi.json` (public; it describes, it reveals no data).
- **Contract test:** every endpoint is called with a real key, and its fields and types are compared with the schema.
- **Settings → Developer** shows an "API reference" (endpoints with their scope, webhook events, how signatures and retries work) and links the OpenAPI file for tools such as Postman.

## 3. Webhook event catalog (§34–35)

Before B53 a subscription could name any internal event (backups, settings, billing, security …) and receive its internal payload. Now:

- `WebhookEventCatalog` lists 11 commerce events, each with its documented payload fields:
  - order.created, order.cancelled;
  - payment.succeeded, payment.refunded;
  - shipment.created, shipment.status_changed, shipment.cancelled;
  - return.requested, return.approved, return.received, return.refunded.
- Subscribing to anything else is refused (422).
- Events outside the catalog are no longer delivered, even for subscriptions made before B53.
- Only documented fields leave the platform.
- The Developer page offers the events as checkboxes (from the spec) instead of free text.
- Changing a payload needs a new event version. Adding an event is additive.

## 4. Idempotency-Key (API-007; §42)

The `idempotent` middleware runs on:
- creating orders;
- payment refunds and manual confirmation;
- return refunds and refund approval;
- shipments;
- store credit adjustments;
- payment notices;
- Super Admin invoice payments and credit notes.

How it behaves:

| Case | Answer |
|---|---|
| Same caller, same call, same key and same body within 24 h | The first answer is replayed with `Idempotent-Replayed: true`; nothing runs again |
| Same key, different body | 422 `idempotency_key_reused` |
| The first request is still running | 409 `idempotency_in_progress` with `Retry-After` |
| Malformed key | 400 `idempotency_key_invalid` |
| A 5xx answer | Not kept, so a retry runs for real |

- Keys are scoped to the signed-in person, customer or API key.
- The header is optional. Endpoints that already take an `idempotency_key` field (orders, checkout, payments) keep it.
- Rows are kept 24 h; `security:prune` removes expired ones. `idempotency_keys` is classified PURGE in StoreDataMap.

## 5. Deterministic pages (API-008)

Developer API lists now have a fixed order: by id, and orders newest first with ties broken by id. Before this, products, customers and inventory had no order, so the same record could appear on two pages or on none.

## 6. End-to-end tests (TEST-009)

- `php artisan e2e:prepare` (refuses in production) creates or refreshes:
  - a launched demo store;
  - its owner, with fresh random passwords and a two-step secret;
  - a customer;
  - a read-only API key.

  It writes them only to `storage/app/e2e/credentials.json` (outside Git).
- Playwright (`playwright.config.ts`, `tests/e2e`, `npm run e2e`) runs four journeys in Chromium:
  1. A guest buys with cash on delivery.
  2. A customer signs in, buys, and finds the order under Your orders.
  3. The store owner signs in with password and a TOTP code (computed as an authenticator app would) and finds the guest's order.
  4. An integration reads the developer API: paging, the error shape on a scope refusal, and the OpenAPI file.
- The new CI job `e2e`: MySQL, built assets, migrate and seed, `e2e:prepare`, `php artisan serve`, Playwright. On failure the report, traces and logs are kept for 7 days.

## 7. Not built / limits

- An OpenAPI description for the first-party admin and storefront APIs. They are internal to the apps (Module 31 §51 requires public APIs). Their errors follow the same shape.
- A developer portal and sandbox (§48–49), SDKs (§50), and breaking-change detection between spec versions (§65.1). The committed spec makes diffs visible in review.
- A `categories:read` scope exists without an endpoint. It is no longer offered on the Developer page.
- Developer API ids are numeric ids (as since B18). Public ULIDs would need v2.
- Package API quotas (API-006).
