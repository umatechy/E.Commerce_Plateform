============================================================
PHASE B53 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B53 — API quality (gap G13):
- SRS API-007, API-008, API-010, API-012, TEST-009.
- Master Development Prompt §15–16.
- Module 31: §2.23–2.24, §34–35, §40–42, §51, §60, §65–66.

Owner request (2026-10-14): "is k bad b53 start kro" (after owner decision 16:
the two super admins, saved locally and in memory, with their names and
numbers kept out of Git).

Starting point:
- v1.1 at 30666db (B52 and decision 16, CI green).
- Errors were JSON, but without one shape; some carried a code and none
  carried the request ID; local debug answers could include traces.
- No OpenAPI description.
- Webhook subscriptions accepted any internal event type and sent its
  internal payload.
- No generic idempotency.
- Developer API lists for products, customers and inventory had no fixed
  order.
- No end-to-end tests in CI.

Implementation Summary:
1. NormalizeApiErrors: every /api error has code, message, details and
   requestId.
   - Existing keys (errors, code, extras) are kept, so the apps did not
     change.
   - Debug data is dropped outside a local debug run, and 5xx answers get a
     generic message.
   - Unhandled /api exceptions are always answered as JSON.
2. OpenAPI 3.1 of the developer API and its webhooks (DeveloperApiSpec).
   - Built from the routes; a route without documentation stops the build.
   - `php artisan api:openapi` writes docs/api/openapi-developer-v1.json,
     also served at GET /api/dev/v1/openapi.json.
   - CI checks that the committed file matches the code and lints it
     (Redocly 2.63.0).
   - A contract test compares every endpoint's real answer with its schema.
3. Webhook event catalog: 11 commerce events with documented fields.
   - Subscribing to anything else is refused.
   - Events outside the catalog are not delivered, and only documented
     fields are sent.
4. Idempotency-Key on retry-sensitive writes (orders, refunds, manual
   payment confirmation, shipments, store credit, payment notices, invoice
   payments, credit notes).
   - Same request: the first answer is replayed. Different request with the
     same key: 422. Still running: 409. 5xx answers are not kept.
   - Keys are kept 24 hours and pruned daily.
5. Developer API pages have a fixed order (API-008).
6. End-to-end tests:
   - `php artisan e2e:prepare` (refuses in production) writes fresh data
     outside Git.
   - Playwright runs four journeys: guest checkout, customer account, owner
     sign-in with a TOTP code, and the developer API.
   - The new CI job `e2e` runs them on the built app; reports are kept on
     failure.
7. Settings → Developer:
   - an API reference read from the OpenAPI file (endpoints, scopes,
     webhook events, signatures, retries) with a link to the file;
   - webhook events chosen from checkboxes instead of typed;
   - the endpoint-less `categories:read` scope is no longer offered.

Docs: docs/architecture/b53-api-quality.md, docs/security/b53-security-review.md,
docs/api/openapi-developer-v1.json, gap matrix (G13 closed; Module 31,
API-007, API-010, API-012, TEST-009).

Found and fixed on the way:
- Developer API lists without a fixed order could repeat or skip records
  between pages.
- Webhooks exposed internal events and payloads to third parties.
- MfaTest expected no `code` on a plain 403. Every error now has one, and
  the test checks that it is `forbidden`, not an MFA prompt.

Decisions to confirm:
- Error codes are lowercase snake_case, matching every code the platform
  already gives. The prompt's example is UPPER_CASE ("similar to").
- The API description has no licence: that is the owner's choice.
- Idempotency keys are kept 24 hours.

Tests:
- New: ApiQualityTest (6, 150 assertions), Playwright journeys (4),
  developer.test.tsx (1).
- MfaTest is adjusted (see above).
- PHP suite: 1248 passed and 1 failed (MfaTest, fixed and re-run: 20
  passed).
- PHPStan: no errors.
- Vitest: 249 passed (40 files).
- ESLint and TypeScript clean; production build OK.
- Playwright E2E locally: 4 of 4 passed, twice.

Browser check (real Chromium, B46 Tax Store owner, MFA and step-up on): 2 of
2 passed, no 5xx.
1. Settings → Developer shows the API reference: 7 endpoints with their
   scopes and 11 webhook events.
2. A webhook added by ticking order.created and shipment.status_changed
   (step-up); the signing secret was shown once.

Local data note:
- Local developer applications "B53 check …" and the E2E store "E2E Shop"
  were created by the checks.
- Super admins of decision 16: Muhammad Haroon Rasheed and Ghulam Mustafa
  are set locally. Their WhatsApp numbers are saved as alert recipients;
  WhatsApp alerts stay off until Umar Techy's WhatsApp account is connected.

Not built (b53-api-quality.md §7):
- OpenAPI for the first-party admin and storefront APIs;
- developer portal, sandbox and SDKs;
- breaking-change detection between spec versions;
- public ULIDs in a developer API v2;
- package API quotas.

CI: pending — to be recorded after the push.

Next (on the owner's word): B54 — help and documentation (gap G12).
