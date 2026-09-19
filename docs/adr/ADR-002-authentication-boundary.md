# ADR-002 — Authentication Boundary: Sanctum vs Developer API Tokens

1. **ADR ID:** ADR-002
2. **Title:** Authentication Boundary — Laravel Sanctum (first-party) vs Developer API
   Tokens/OAuth/JWT (third-party)
3. **Status:** PROPOSED
4. **Date:** 2026-09-18
5. **Decision Owners:** Senior Software Architect / Security Engineer, pending Project
   Owner approval

---

## 6. Context

The approved technology stack specifies **Laravel Sanctum** for authentication. Module
31 (API & Developer Platform) separately describes **OAuth 2.0**, **API keys**, and
**JWT/service tokens** as authentication mechanisms for the developer/integration
surface (Module 31 §8.5, §2.14–2.16, §17). Read in isolation, this could look like a
stack conflict. The Final Readiness Review (prior checkpoint) flagged this as a
scope-clarification item, not a hard contradiction, and recommended this ADR to resolve
the wording without changing Module 31's functional requirements.

## 7. Problem Statement

Without an explicit boundary, implementers could either (a) incorrectly try to replace
Sanctum with JWT platform-wide, contradicting the approved stack, or (b) incorrectly try
to force Sanctum to serve third-party OAuth/partner integration use cases it was not
designed for, weakening Module 31's requirements (scoped tokens, OAuth client
management, PKCE, service-to-service tokens).

## 8. Decision

Two authentication surfaces exist side by side, each authoritative for its own actor
type, both enforced server-side and both tenant-bound via ADR-001:

**Surface A — First-Party Authentication (Laravel Sanctum):**
Covers: Store Owner/Admin/Manager/Staff web sessions, the Super Admin panel, and the
approved future Flutter mobile client. Uses Sanctum's SPA cookie-session mode for the
Inertia.js web app, and Sanctum personal access tokens (bearer tokens) for the future
Flutter client and any first-party mobile/API consumer. This is the *default and only*
authentication mechanism for anything under Modules 01–10, 13–26, 28–30, 32–35 (i.e.
the core platform, storefront, admin, and Super Admin surfaces).

**Canonical statement:** Laravel Sanctum is the platform's **current, canonical**
authentication mechanism for the web/admin/first-party API boundary. OAuth and JWT are
**not** being introduced now merely because Module 31 mentions them — they remain a
**future/conditional architecture**, reserved exclusively for the Module 31 Developer
API surface, and are only built out when that specific module's implementation phase is
explicitly approved. Nothing in this ADR authorizes building OAuth/JWT infrastructure
ahead of that approval.

**Surface B — Developer/Integration API Authentication (Module 31 scope only, future/
conditional):**
Covers *only* third-party integration partners, marketplace apps (Module 27), and
service-to-service/internal contexts explicitly described in Module 31 — and only once
that module's implementation is explicitly approved (see §19 Migration/Rollout). Within
this surface, once approved:
- **OAuth 2.0** is used where a third-party application acts on behalf of a store owner
  who must explicitly grant consent (marketplace app installs).
- **API keys** are used for approved server-to-server integrations where no per-user
  consent flow is needed (tenant-bound, scoped, rotatable, per Module 31 §2.14–2.16).
- **JWT/service tokens** are reserved for controlled *internal* service-to-service calls
  and short-lived, narrowly-scoped tokens issued *by* the OAuth/API-key layer — JWT is
  not a separate login mechanism a customer or store user ever sees; it is the token
  *format* the Developer API layer may issue after an OAuth or API-key exchange.

Sanctum tokens issued to Surface A users are **never** valid on Developer API (Module
31) endpoints, and Developer API tokens are **never** valid on first-party web/admin/
mobile routes. The two token types are routed through separate middleware groups and
separate route prefixes (see ADR-005 for the exact URL convention) so a token from one
surface cannot be presented to the other surface's routes even by mistake.

## 9. Detailed Implementation Rules

- **Token scope:** Sanctum tokens carry the authenticated user's ability set (role/
  permissions from Module 02, resolved server-side per request — never trusted from the
  token itself beyond identity). Developer API tokens carry an explicit, minimal scope
  list defined at issuance (Module 31 §2.4-style entitlement/quota enforcement).
- **Token lifecycle:** Sanctum SPA sessions follow standard Laravel session expiry;
  Sanctum personal access tokens (mobile) are long-lived but individually revocable per
  device. Developer API OAuth access tokens are short-lived with refresh tokens; API
  keys are long-lived but rotatable and revocable without affecting other keys.
- **Revocation:** Both surfaces support immediate, per-token revocation (a compromised
  mobile device or a compromised integration must be revocable without logging out every
  other session/integration).
- **Permission enforcement:** Always re-checked server-side per request via Policies
  (Module 02) for Surface A, and via the entitlement/scope check (Module 04 + Module 31)
  for Surface B — never trusted from the token payload alone, consistent with Module 31
  §2.4–2.5.
- **Tenant binding:** Every token of either type is bound to exactly one tenant context
  at issuance (Surface A: the user's store membership; Surface B: the integration's
  authorized store), enforced through ADR-001's `TenantContext` resolution — a
  Developer API key issued for Store A can never resolve Store B's context.
- **Rate limiting:** Enforced server-side for both surfaces (Module 31 §2.16); Developer
  API additionally enforces package-based quotas (Module 31 §1.8, Module 04).
- **Audit logging:** Every authentication event, token issuance, and revocation is
  logged for both surfaces (Module 32).
- **Webhook authentication (distinct from user/token authentication):** Inbound
  webhooks (from payment/shipping providers, etc.) are never authenticated via Sanctum
  or OAuth/JWT user tokens — they are verified via provider-specific request signatures
  and resolved to a tenant through the registered endpoint/credential, per ADR-001
  Layer 6 and ADR-004's event architecture, not through either authentication surface
  defined in this ADR.

## 10. Alternatives Considered

- **A — Use Sanctum everywhere, including Developer API:** Rejected; Sanctum does not
  natively provide OAuth 2.0 authorization-code/consent flows or PKCE required by
  Module 31 for third-party marketplace apps.
- **B — Use JWT/OAuth everywhere, including first-party web/admin:** Rejected; directly
  contradicts the approved technology stack, which specifies Sanctum, and adds
  unnecessary complexity (token refresh handling, key rotation) to the first-party
  Inertia.js SPA that Sanctum's cookie-session mode already handles simply and securely.
- **C (Selected) — Two clearly bounded surfaces, as decided above.**

## 11. Why Alternatives Were Not Selected

Option A cannot satisfy Module 31's explicit OAuth/consent/PKCE requirements without
effectively reimplementing an OAuth layer on top of Sanctum, at which point it is no
longer "using Sanctum" in any meaningful sense. Option B would require changing the
approved stack, which this ADR package is explicitly not permitted to do.

## 12. Security Implications

Clear separation reduces the risk of privilege confusion (e.g., a leaked Developer API
key must not be usable to log into the admin panel, and a leaked admin session token
must not be usable to call partner-scoped Developer API endpoints). Both surfaces
inherit ADR-001's tenant-binding, so neither surface can be used to escape tenant
isolation.

## 13. Multi-Tenant Implications

Both surfaces resolve through the same ADR-001 `TenantContext`; there is no separate,
weaker tenant-resolution path for Developer API tokens.

## 14. Database Implications

Two distinct token storage concerns: Sanctum's standard `personal_access_tokens` table
(first-party), and a Module-31-owned `api_credentials`/`oauth_clients`/`api_keys`
table set (Developer API), each carrying an explicit `store_id` foreign key (see
ADR-003). These are never merged into one table, to keep the security boundary
structurally visible in the schema.

## 15. API Implications

Route groups are physically separated by prefix and middleware (see ADR-005): first-
party routes under the main web/API surface using the `sanctum` guard; Developer API
routes under a distinct versioned prefix using an `api-key`/`oauth` guard stack. A
request cannot be routed to both.

## 16. Testing Implications

- Feature tests asserting a Sanctum token is rejected on every Developer API route, and
  vice versa.
- OAuth consent-flow tests (grant, deny, revoke) per Module 31.
- Rate-limit and quota enforcement tests per package tier (Module 04 + Module 31).
- Token revocation tests for both surfaces.

## 17. Operational Implications

Support/security staff need visibility into both token types when investigating an
incident; audit logs (Module 32) must clearly label which surface issued/used a given
token.

## 18. Scalability Implications

None beyond standard Sanctum/OAuth scaling characteristics; both are stateless-enough
to scale horizontally behind the approved Nginx/Redis infrastructure.

## 19. Migration / Rollout Considerations

Surface A (Sanctum) is required starting Milestone 0 — Step 0.6, and is the **only**
authentication surface built until further notice. Surface B (Developer API / Module
31 — OAuth/API keys/JWT) is **not** required until the corresponding implementation
phase (Phase 5 in the dependency graph), is explicitly future/conditional, and must not
be scaffolded, stubbed, or partially built prematurely. This ADR should be referenced
— and re-confirmed as still correct, not rewritten by default — when that phase is
explicitly approved to begin.

## 20. Consequences

**Positive:** Resolves the wording ambiguity without touching Module 31's functional
requirements; keeps the approved stack intact; gives implementers one clear rule
("Sanctum = first-party, OAuth/API-key/JWT = Module 31 Developer API only").

**Negative / trade-offs:** Two authentication code paths to maintain instead of one —
accepted as necessary because the two actor types (store users vs. third-party
integrations) have genuinely different security requirements.

## 21. Future Reconsideration Conditions

Reconsider if a future requirement emerges for first-party mobile SSO/enterprise
identity federation (SAML/OIDC) that Sanctum cannot serve — at that point a dedicated
ADR (not this one) should evaluate adding an OIDC layer for Surface A specifically.

## 22. Related Project Documents

Approved Technology Stack (Milestone-0 document); Module 02 (Authentication, Users,
Roles & Permissions); Module 31 §1, §2.4–2.6, §2.14–2.26, §8.5, §17 (API & Developer
Platform); Module 04 (Subscription, Packages & Feature Entitlements); Module 32
(Security, Audit & Compliance); ADR-001 (Tenant Resolution and Isolation).
