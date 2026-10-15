# B56 — The app marketplace (gap G19, Module 27)

Sources:
- SRS APP-001 to APP-007.
- Module 27: §5–28 (principle, types, ownership, registry, lifecycles, isolation, permissions, approval, versioning, compatibility, installation safety, uninstalling, data), §34–36 (OAuth, keys, credentials), §45–47 (configuration), §52, §56–59 (events, health, suspension), §64–66 (metering, search, listing), §71–73 (review, trust), §84–89 (audit, incident, kill switch, privacy).
- Module 04 §8 and §54–55: packages and API access.

## 1. Principle

Apps act **only** through the developer API and webhooks (B18, B53), with credentials made for one store:
- no app code runs on the platform;
- no app touches the database (§5, §27);
- an app can read only what its version declares and the store granted;
- the scopes are read-only today (`products:read`, `orders:read`, `customers:read`, `inventory:read`);
- an app cannot change, refund or pay anything.

## 2. Data model

| Table | What |
|---|---|
| `marketplace_apps` | Platform registry: listing, publisher, privacy/terms, ownership (Umar Techy, partner, third party) and trust label, status (draft, in review, published, suspended, deprecated), pricing (free only), `client_id`, `client_secret_hash` (SHA-256; the secret is shown once), https redirect addresses, webhook address, required package features, current version. |
| `marketplace_app_versions` | Semver version, scopes, events, settings form (schema), data-use statement, release notes, minimum platform version, review record (checks, reviewer, note), status (draft, in review, published, superseded). |
| `app_installations` | Per store (BelongsToTenant): version, status (awaiting connection, active, disabled, suspended, uninstalled), granted scopes and events, encrypted settings, the store's developer application, who installed it and when. |
| `app_authorization_codes` | One-time codes: SHA-256 hash, redirect address, 10-minute expiry, used time. |

`app_installations` and `app_authorization_codes` are classified PURGE: they go with a store's data.

## 3. Registry and review (Super Admin → Marketplace; capability `marketplace`)

1. **Add app**: the listing and the publisher. The client id and secret are shown once.
2. **Version**: semver, higher than the published one. It declares the scopes, the events, the settings form and the data-use statement.
   - The settings form is checked (field types text, number, boolean, select, url, secret; at most 30 fields).
   - Scopes or events need a redirect address; events need a webhook address.
3. **Send for review.** Then someone **other than the submitter** confirms the six review checks (permissions, data use, privacy, security, tested, support) and publishes. Every step is in the platform audit log.
4. **Kill switch**: **Suspend everywhere** with a reason (step-up).
   - Every installation's developer application is suspended at once, so keys answer 401 and webhooks stop.
   - **Resume** (step-up) restores exactly the installations that were active; paused ones stay paused.
5. **Retire from catalog** stops new installations. **New client secret** (step-up) invalidates the old one.

## 4. A store installs an app (Store → Apps)

- Everyone with `developer_platform.view` can look. Installing, connecting, settings, updates, pausing and removing need `developer_platform.manage`, which only the Owner holds.
- Each app page shows:
  - what the app can see, with **Personal data** marked for orders and customers;
  - the events it receives;
  - the data-use statement, the publisher and its privacy policy.
- **Compatibility** (§19, §21): the app must be published; the platform version must be high enough; the store must have the required package features — and `integrations.api` whenever the app reads data (Module 04 §8: "API access" is not in Basic); and the store must be open.
- **Install** (step-up): the Owner ticks that they allow the permissions. The installation waits for the connection, and the browser goes to the app's first redirect address with `code`, `store` and `installation`.
- **Connect** (§34): the app's server calls `POST /api/dev/v1/oauth/token` with the code, its client id and secret and the redirect address, within 10 minutes and only once.
  - Every failure answers 400 `invalid_grant`.
  - On success the platform creates the store's developer application, an API key of exactly the granted scopes and, with events, a webhook subscription. Their secrets are returned once and kept only as hashes.
  - Connecting again revokes the earlier credentials.
- **Settings** (§45–47): the form follows the version's schema and is validated on the server.
  - Values are stored encrypted. Secret fields are never sent back to the admin; empty keeps the saved value.
  - The app reads its settings with `GET /api/dev/v1/installation`.
- **Update** (§76): a newer published version that asks for more scopes or events needs the Owner's agreement (`consent_required`); the key's scopes and the webhook events change at once.
- **Pause / Turn back on**: suspends or reactivates the store's developer application.
- **Remove** (step-up, §23, §79): revokes the key, disables the webhooks, voids open codes and deletes the settings. It can be installed again later.
- **Usage and health** (§56–57, §64): 30-day reads, failed reads (5xx), webhook deliveries and failures, last use. Health is working, some errors, failing (half or more of the last day's deliveries failed, at least 5), not used yet, or not running.

## 5. Fixed in passing (B18)

Suspending a developer application did not stop its API keys (`ApiKeyService::verify` did not look at the application) or its webhooks. Both now require an active application. The Super Admin's "suspend developer application" now really cuts access, and the marketplace kill switch relies on it.

## 6. Developer API additions

`POST /oauth/token` and `GET /installation` are in the OpenAPI description (`DeveloperApiSpec::APP_ENDPOINTS`, tag "Marketplace apps"). The committed `docs/api/openapi-developer-v1.json` is regenerated.

## 7. Conflict reported, not changed

Module 04 §8 lists "API Access: NO" for Basic. B18's developer API (Settings → Developer) is not limited by package, so a Basic store can create its own API keys. The marketplace applies the package rule (`integrations.api`) to apps that read data. Whether B18's private keys should follow the same rule is the owner's decision.

## 8. Not built / limits

- Paid apps and app billing (§61–62): every app is free until pricing and Module 29 integration are decided.
- Plugin code, storefront, checkout and admin UI extensions, extension jobs and resource limits (§48–54): apps run on their own servers.
- Write scopes (`*.write`): no write endpoints exist yet.
- A self-service developer portal and sandbox store (§69–70): Umar Techy registers apps.
- Signed packages (§22): no package is uploaded.
- App reviews and ratings (§67), and screenshots and logos in listings (an initial is shown).
- A notice to the app when a store removes it: its key starts answering 401.
