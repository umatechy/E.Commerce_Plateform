# ADR-001 — Tenant Resolution and Isolation Mechanism

1. **ADR ID:** ADR-001
2. **Title:** Tenant Resolution and Isolation Mechanism
3. **Status:** PROPOSED
4. **Date:** 2026-09-18
5. **Decision Owners:** Senior Software Architect / Security Engineer / Database Engineer (this AI role-set), pending Project Owner approval

---

## 6. Context

The platform is approved as a **shared MySQL, single-codebase, modular-monolith,
multi-tenant SaaS** (Milestone-0 approved stack; Bible §6 Tenant Model, §10 System
Architecture Principles; SRS §4 Multi-Tenant Requirements; Module 03 — Multi-Tenant &
Store Management). Every higher-level document treats tenant isolation as
**non-negotiable** and requires it across database, API, files, cache, jobs, webhooks,
search, exports, analytics, notifications, and logs. None of these documents specify the
exact Laravel-level mechanism — this is intentionally left to implementation, per Module
03 §10 ("Tenant context should be derived from authenticated identity... must never be
trusted solely from client input").

## 7. Problem Statement

Without one documented, mandatory mechanism, different developers/AI sessions could each
implement tenant scoping differently (some using global scopes, some manual `where()`
clauses, some forgetting cache/queue isolation). A single missed scope is a tenant-data
leak — the most severe possible failure for this platform. A single, layered,
defense-in-depth mechanism must be defined once and reused everywhere.

## 8. Decision

Adopt a **layered (defense-in-depth), server-resolved tenant context model** with the
following mandatory layers. No layer replaces another — each is an independent safety
net.

**Layer 0 — Identification:** Every tenant has an immutable internal `Tenant`/`Store`
identifier (see ADR-003 for exact type) that never changes across package upgrade/
downgrade, matching Bible §6 and Master Index rule #2.

**Layer 1 — Resolution:** A dedicated `ResolveTenantContext` middleware resolves the
active tenant **only** from server-trusted sources, in this strict priority order:
1. The authenticated user's store membership (session/token → user → store link table),
   for store-side users.
2. The Super Admin's explicitly-selected impersonation/support context, which is itself
   authenticated, permissioned, and audit-logged (see Layer 7).
3. The resolved public storefront domain/subdomain (Module 19 domain mapping), for
   anonymous storefront requests.

The middleware **never** trusts a client-supplied `tenant_id`/`store_id` in the request
body, query string, or header for determining *which* tenant a request may act on. A
client-supplied ID, if present at all, is used only as a value to be **verified** against
the server-resolved context, never as the source of truth.

**Layer 2 — Context Container:** A request-scoped `TenantContext` singleton (bound once
per request/job) holds the resolved tenant ID plus a `platform` flag (true only for
Super-Admin/platform-level operations with no tenant scope) and an `impersonation` flag.
All application code reads tenant identity from this container — never from
`request()->input('store_id')` or similar.

**Layer 3 — Database Query Isolation (primary):** Every tenant-owned Eloquent model uses
a **global scope** that automatically applies `WHERE store_id = ?` from the
`TenantContext`, and a corresponding **creating** event that auto-fills `store_id` on
insert. Application code must never need to remember to filter by tenant manually.

**Layer 4 — Relationship-Level Protection (secondary):** All `belongsTo`/`hasMany`
relationships between tenant-owned models are additionally constrained so that a parent
resolved for Tenant A cannot resolve children belonging to Tenant B, even if a numeric ID
for Tenant B's row is guessed and passed in. This is enforced via route-model-binding
resolution that re-checks `store_id` ownership, not only via the global scope.

**Layer 5 — Raw Query Protection:** Any raw SQL / query-builder call that bypasses
Eloquent (reports, analytics, exports, search indexing) MUST explicitly include the
tenant filter, and MUST pass through a mandatory code-review checklist item and an
automated static check (see §16 Testing Implications). Raw queries without an explicit,
reviewed tenant filter are treated as a security defect, not a style issue.

**Layer 6 — Cache/Queue/Event/Webhook Context:**
- Cache keys for tenant-owned data are always prefixed `tenant:{store_id}:...`; a shared
  cache helper enforces the prefix so a raw `Cache::put('key', ...)` without a tenant
  prefix cannot be used for tenant data.
- Every queued job that touches tenant data carries the resolved `store_id` as an
  explicit constructor property (not re-resolved from ambient state at execution time,
  since jobs may run outside a request context) and re-applies the global scope inside
  the job using that stored ID.
- Every domain/integration event (see ADR-004) carries `store_id` in its payload.
- Every outbound webhook is signed and tagged with the originating `store_id`; inbound
  webhooks resolve tenant from the registered endpoint/credential, never from webhook
  body content alone (Module 31 §2.18, §17).

**Layer 7 — Super Admin Cross-Tenant Access (explicit bypass, not implicit absence of
scope):** Cross-tenant access exists **only** for the Umar Techy Super Admin surface
(Module 30) and is implemented as an explicit, separately-permissioned
`platform` context — never as "just don't apply the scope." Every cross-tenant read is
permission-checked, and every cross-tenant write/impersonation session is audit-logged
with actor, target tenant, reason, and timestamp (Module 32).

**Layer 8 — File/Object Storage, Search, Imports/Exports, Reports, Analytics,
Notifications, Logs:** Each of these follows the same rule — tenant ID is part of the
storage path / index namespace / partition key / recipient resolution, resolved from
the server-side `TenantContext`, never from client input:
- Object storage paths: `stores/{store_id}/...`.
- Search: tenant ID is a mandatory filter field on every query against the search index,
  enforced at the query-builder layer, not left to the caller.
- Imports/exports: the job is bound to one `store_id` at creation time; output files are
  stored under that tenant's path and access-checked on download.
- Reports/analytics: aggregation queries are tenant-scoped by default; any genuinely
  cross-tenant aggregate (platform-level Super Admin analytics) is a distinct,
  separately-permissioned code path, not a toggle on the tenant-scoped one.
- Notifications: recipient resolution is always through the tenant-scoped customer/user
  record.
- Logs/audit records: every log line that touches tenant data includes `store_id`;
  platform-level logs (infra, deploy) are distinguished from tenant-activity logs.

**Layer 9 — Emergency / System-Level Operations:** Rare operations that must legitimately
run without a specific tenant context (platform cron/maintenance jobs, database
migrations, platform-wide broadcast notifications, incident-response scripts) run under
an explicit `platform_system` context — distinct from both a normal tenant context and
from Super Admin impersonation (Layer 7). This context is never the default; it must be
explicitly constructed by infrastructure-level code (scheduled console commands,
deployment scripts) and is not reachable from any HTTP-authenticated request path. Every
use of `platform_system` context is logged with the initiating process/command name.

## 9. Detailed Implementation Rules

- A normal store user, even if they manually edit a request to reference another
  store's numeric ID, MUST receive a 404 (not a 403, to avoid confirming the record's
  existence) because Layer 3 + Layer 4 make the row unreachable, not merely forbidden.
- No controller, service, or job may construct a tenant-scoped query without going
  through the model's default global scope or an explicitly reviewed raw-query
  exception.
- The `TenantContext` MUST be immutable for the lifetime of a request/job once resolved;
  changing tenant mid-request is not permitted (a new request/job is required).
- Impersonation (Super Admin acting "as" a store) MUST set an explicit
  `impersonation = true` flag and MUST still resolve through the same `TenantContext`
  container — it does not get a separate, less-scoped code path.
- No mechanism anywhere in the platform (route parameter, request body, query string,
  header, cache key, file path, queued-job payload, webhook reference, or API request)
  may be used to select or override tenant context on its own; every one of these is,
  at most, a value re-verified against the already server-resolved `TenantContext`,
  never the source that establishes it.

## 10. Alternatives Considered

- **A — Database-per-tenant:** Rejected for the initial phase; contradicts the approved
  "shared MySQL" decision and the commercial model of onboarding many small/medium
  stores cheaply on shared infrastructure (matches prior product-discussion: "100 stores
  don't need 100 servers").
- **B — Schema-per-tenant (single MySQL server, many schemas):** Rejected; MySQL
  schema-per-tenant at hundreds/thousands of tenants creates migration and connection-
  pool operational overhead disproportionate to the platform's target segment (small/
  medium stores), and still requires most of the same application-layer discipline as
  Option C.
- **C — Row-level isolation only via manual `where()` calls in every query (no global
  scope):** Rejected; this is the highest-risk option — a single forgotten `where()` is
  a full tenant-data leak, with no structural safety net.
- **D (Selected) — Row-level isolation via shared MySQL + mandatory global scope +
  layered defense-in-depth (this ADR):** Matches the approved stack, matches the
  commercial model, and provides multiple independent safety nets instead of one.

## 11. Why Alternatives Were Not Selected

Option A/B were rejected because they contradict the already-approved "shared MySQL"
decision and the SaaS cost model discussed for the platform. Option C was rejected
because it has no structural protection against a single developer mistake — given that
this codebase will be extended by multiple developers and AI coding sessions over time,
relying on discipline alone is not defense-in-depth.

## 12. Security Implications

This ADR is itself a direct implementation of Module 32 (Security, Audit & Compliance)
and the "non-negotiable tenant isolation" rule repeated across the Master Index, Bible,
and SRS. The layered model specifically defends against: IDOR (Insecure Direct Object
Reference) attacks, cache poisoning across tenants, cross-tenant job/queue data leakage,
webhook spoofing, and Super Admin privilege misuse (via mandatory audit logging of every
cross-tenant action).

## 13. Multi-Tenant Implications

This ADR **is** the multi-tenant isolation implementation. It applies to every module
(01–35) that touches tenant-owned data — i.e., effectively all of them except
platform-only modules.

## 14. Database Implications

Every tenant-owned table requires a `store_id` (see ADR-003 for exact column
convention) as a foreign key, and a composite index leading with `store_id` for every
query pattern that filters by tenant (see ADR-003 §Indexing).

## 15. API Implications

Every authenticated API request (first-party or Developer API — see ADR-002) resolves
tenant context server-side via the same middleware chain before reaching any controller.
Developer API tokens are tenant-bound at issuance (Module 31 §2.4) and cannot be used to
address a different tenant.

## 16. Testing Implications

Mandatory automated test categories before any commerce feature ships:
- **Tenant-isolation regression suite:** for every tenant-owned model, a test that
  creates two tenants' data and asserts Tenant A's authenticated session cannot read,
  update, delete, or enumerate Tenant B's records via any exposed route.
- **Raw-query lint/static-check:** a CI check (Step 0.11/PHPStan custom rule or a
  lightweight static grep-based check) flags any raw DB facade call in tenant-owned
  domains that lacks a `store_id` condition, for manual review.
- **Cache-key check:** a test asserting the shared cache helper rejects unprefixed keys
  for tenant-scoped cache stores.
- **Job/queue test:** a test asserting a dispatched job serializes and re-applies the
  correct `store_id` on execution, including after a queue worker restart.

## 17. Operational Implications

Super Admin cross-tenant actions must appear in an operator-visible audit log (Module
30/32) in near-real-time, so operational/security staff can detect misuse quickly.

## 18. Scalability Implications

Row-level isolation with global scopes on a shared MySQL database scales adequately for
the platform's stated target (small/medium stores, growing to hundreds/thousands).
Composite indexes leading with `store_id` (ADR-003) keep per-tenant query cost low as
row counts grow. If a small number of very large tenants (Premium/Enterprise) later need
dedicated resources, Module 35 (Future Expansion Framework) already anticipates this —
this ADR does not block a future move of specific large tenants to isolated database
connections, because the `TenantContext` abstraction is connection-agnostic.

## 19. Migration / Rollout Considerations

This mechanism must be built once, in Milestone 0 — Step 0.5, as shared foundation
code, before any tenant-owned business table is created (Step 0.4 onward). Retrofitting
the global-scope pattern onto tables created without it would require a dedicated
migration/audit pass — this ADR exists specifically to avoid that.

## 20. Consequences

**Positive:** A single, auditable, hard-to-bypass pattern; new developers/AI sessions
have one documented way to write tenant-safe code; strong defense-in-depth reduces the
blast radius of any single mistake.

**Negative / trade-offs:** Slightly more upfront foundation work in Milestone 0 (base
model traits, middleware, cache helper, job base class) before any business feature can
be built; raw-query exceptions require a documented review step, adding minor process
overhead.

## 21. Future Reconsideration Conditions

Reconsider this ADR if: (a) a small number of Premium/Enterprise tenants require
dedicated database resources for compliance or scale reasons, (b) MySQL row-level
performance at very high tenant/row counts becomes a measured bottleneck, or (c) a
formal PCI-scoped architecture (Module 31 §17.2) requires physical data separation for
payment-related tables.

## 22. Related Project Documents

Bible §6 (Tenant Model), §10 (System Architecture Principles); SRS §4 (Multi-Tenant
Requirements); Module 03 (Multi-Tenant & Store Management), Module 30 (Super Admin),
Module 31 §2.4–2.6, §2.18 (API tenant/webhook rules), Module 32 (Security, Audit &
Compliance); Master Index non-negotiable principle #1.
