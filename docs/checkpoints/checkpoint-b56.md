============================================================
PHASE B56 CHECKPOINT
============================================================

Phase:
Development Phase B — release baseline v1.1

Module:
B56 — The app marketplace (gap G19):
- SRS APP-001 to APP-007.
- Module 27; Module 04 §8, §54–55.

Owner request (2026-10-15): "G bilkul b56 start kro".

Starting point:
- v1.1 at d31b445 (B55, CI green).
- B18 developer applications, scoped read-only API keys, webhooks and
  request logs existed.
- No marketplace, registry, installation or OAuth connection.

Implementation Summary:
1. Registry (Super Admin → Marketplace; new capability `marketplace`).
   - apps and semver versions: scopes, events, settings form, data-use
     statement, minimum platform version;
   - client id and secret: the secret is hashed and shown once, and can be
     rotated with step-up;
   - two-person review: six checks, the submitter cannot publish;
   - retire from catalog;
   - kill switch: suspend everywhere, with a reason and step-up; resume
     brings back exactly what ran.
2. Store → Apps (look: developer_platform.view; act: the Owner only).
   - the catalog, with search and categories;
   - app pages with permissions (personal data marked), events, data use
     and publisher;
   - compatibility checks: published, platform version, package features,
     `integrations.api` for apps that read data, store open.
3. Install with consent and step-up, then an OAuth authorization-code
   connection.
   - The code is one-time, 10 minutes, hashed, and bound to the redirect.
   - `POST /api/dev/v1/oauth/token` with the client secret creates the
     store's developer application, a key of exactly the granted scopes
     and the webhook subscription, returned once.
   - `GET /api/dev/v1/installation` gives the app its grant and the store's
     settings.
4. Settings from the app's form: validated on the server, encrypted,
   secrets never shown again.
5. Update: more permissions need the Owner's agreement; the key and the
   webhooks change at once.
6. Pause, turn back on, connect again, and remove. Removing revokes the
   key, disables the webhooks, voids open codes and deletes the settings.
7. Usage and health over 30 days.
8. OpenAPI: the two app endpoints are documented ("Marketplace apps"), and
   the committed file is regenerated.
9. Help: "Add apps to your store" (store teams), "Build an app for the
   marketplace" (developers), "Reviewing and running marketplace apps"
   (internal); release notes 1.1 updated.

Docs:
- docs/architecture/b56-marketplace.md
- docs/security/b56-security-review.md
- gap matrix: Module 27 row, APP-005, G19.

Found and fixed on the way:
- B18 security bug: suspending or revoking a developer application left
  its API keys working and its webhooks sending.
  - Fix: key verification, webhook routing and delivery now require an
    active application.
  - Test: test_a_suspended_developer_application_no_longer_has_api_access.
- The Super Admin page could not tell the reviewer they had submitted the
  version themselves. The server now says so (`submitted_by_me`), and
  Approve is disabled.

Conflict reported (not changed):
- Module 04 §8 says Basic has no API access, but B18's own developer keys
  are not limited by package.
- The marketplace applies the rule (`integrations.api`).
- Whether Settings → Developer should too is the owner's decision.

Decisions to confirm:
- All apps are free until paid apps and billing are decided.
- Apps that read store data need a package with API access (Business,
  Premium).
- Only the Owner installs, changes or removes apps.
- Umar Techy registers apps; there is no self-service developer portal
  yet.

Tests:
- New: MarketplaceTest (9 tests); apps.test.tsx (1).
- Adjusted: StepUpTest (new step-up routes) and ApiQualityTest (app
  endpoints have no scope).
- PHP suite: 1287 passed.
- Vitest: 254 passed.
- PHPStan, ESLint and TypeScript are clean. Production build OK.
- api:openapi --check is up to date; help:check is valid (60 articles).

Browser check (real Chromium; platform staff and the B46 Tax Store owner
with MFA and step-up): 6 of 6 passed, no 5xx.
1. The Super Admin marketplace lists the published test app and its
   version.
2. The owner reads the permissions, agrees, confirms it is them, and is
   sent to the app with a 48-character code.
3. The app server exchanges the code: products 200, customers 403 (not
   granted), the reused code 400.
4. The installed app is active; settings are saved; the secret is
   encrypted and shown as "Saved".
5. Kill switch: 401 while suspended, 200 after resume.
6. Remove: the key answers 401.

Local data note:
- Test apps "Ledger Sync (check)" (slug b56-check-…), published locally.
- A local staff account b56.reviewer@example.com: super_admin, random
  password, used only to review in the seed script.

Not built (b56-marketplace.md §8):
- paid apps;
- UI extensions and plugin code;
- write scopes;
- developer portal and sandbox;
- signed packages;
- app reviews and logos;
- an uninstall notice to apps.

CI: pending.

Next (on the owner's word): G20 Affiliate and Reseller (Module 28), the
next module in the matrix order; or G22 feature flags. G18 AI waits for
the OpenAI key.
