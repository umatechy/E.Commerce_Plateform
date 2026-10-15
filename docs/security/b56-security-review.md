# B56 security review — app marketplace

| Area | Risk | Control | Test |
|---|---|---|---|
| Cross-store access | An app installed in store A reads store B (§12) | Each installation gets its own developer application and key, bound to the store by the existing API key authentication; installations are tenant-scoped | isolation test |
| Over-broad access | An app gets more than it needs or more than the store agreed (§13–15) | Scopes come from the reviewed version; the Owner agrees to each; the key carries exactly those; updates with more need fresh agreement; scopes are read-only; personal data is marked | install, update and scope tests |
| Who may install | Staff connect an app to customer data | Install, connect, update, remove need developer_platform.manage (Owner only) and step-up | install test, StepUpTest |
| Code interception | A stolen code gives store access (§34) | Code: 48 random characters, hashed, 10 minutes, single use, bound to the registered redirect address; exchange needs the client secret; one answer for every failure | exchange test |
| Credential storage | Keys, secrets or settings leak from the database | API key, webhook secret and client secret stored only as hashes and shown once; settings encrypted; secret settings never returned to the admin; token answers `Cache-Control: no-store` | settings and registry tests |
| Malicious or careless app | Reviewed once, misbehaves later (§85–86) | Two-person review checklist; kill switch stops keys and webhooks in every store at once, with a reason; client secret rotation; audit of every step | kill switch test |
| Suspended application still active (B18 bug) | Suspending did not stop keys or webhooks | Key verification, webhook routing and delivery require an active application | suspended application test |
| Uninstall | Access lingers after removal (§23) | Key revoked, webhooks disabled, codes voided, settings deleted in one transaction | uninstall test |
| Webhook destinations | SSRF through an app's webhook address | Existing B18 check: https, public address only | B18 tests |
| Package rules | Basic stores getting API access through apps | `integrations.api` required for apps that read data | install test |

No new dependency and no new secret. The client secrets of apps are platform-held credentials, given to publishers once.
