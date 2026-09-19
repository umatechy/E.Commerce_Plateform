# ADR-005 — API Versioning Convention

1. **ADR ID:** ADR-005
2. **Title:** API Versioning Convention
3. **Status:** PROPOSED
4. **Date:** 2026-09-18 (reviewed 2026-09-18 against Master Prompt — ADR Completion +
   Development Phase B; decision confirmed correct and unchanged, no rewrite required)
5. **Decision Owners:** Senior Software Architect, pending Project Owner approval

---

## 6. Context

Module 31 §2.14 states "API versions MUST be explicit and governed," §2.15 requires
breaking changes to use a new major version or an explicitly governed compatibility
strategy, and Modules 27 and 30 separately reference "API versioning" for
extensions/integrations. The approved stack specifies a "Laravel REST API, Versioned
API architecture" but does not name the exact convention — left to implementation.

## 7. Problem Statement

Without one documented convention, different modules (core storefront/admin API, the
future Flutter mobile API, and the Module 31 Developer API) could each invent a
different versioning scheme, breaking the "consistent, predictable" requirement and
complicating the future Flutter client's promise to "consume the same versioned REST
API."

## 8. Decision

**Version format:** URL-path-based major versioning: `/api/v{n}/...` (e.g. `/api/v1/
products`). Major version numbers only (`v1`, `v2`) — no minor/patch numbers in the URL,
since non-breaking changes do not require a new version at all (see below).

**Initial version:** `v1` for all first-party and Developer API surfaces from their
respective first implementation.

**Scope of "one" API:** The core platform API (storefront, admin, and the future
Flutter first-party client — Surface A from ADR-002) and the Module 31 Developer API
(Surface B) are versioned **independently** of each other, because they evolve for
different audiences and on different cadences:
- First-party/core API: `/api/v1/...` (Sanctum-authenticated, per ADR-002).
- Developer API: `/api/dev/v1/...` (OAuth/API-key-authenticated, per ADR-002), kept
  under a distinct prefix so Surface A and Surface B are unambiguous from the URL alone,
  reinforcing ADR-002's route-level separation.
- Public/unauthenticated storefront-data endpoints (if any are exposed independent of
  the SPA's own Inertia responses) live under `/api/v1/public/...`.

**What triggers a new major version:** Only a genuinely breaking change — removing a
field, changing a field's type/meaning, removing an endpoint, or changing
authentication/authorization semantics. Additive, backward-compatible changes (new
optional field, new endpoint, new optional query parameter) ship within the existing
major version — no version bump required, consistent with Module 31 §2.15's "breaking
changes require a new major version."

**Backward compatibility:** A new major version (`v2`) is only introduced when
accumulated breaking needs justify it, not per feature. When introduced, `v1` continues
to be served, unmodified, for the deprecation window below — both versions are live
application code, not "the old version frozen and unmaintained for security patches."

**Deprecation:** A deprecated version is marked via a standard `Deprecation` and `Sunset`
HTTP response header (per Module 31 §2.24-adjacent documentation-from-source-of-truth
principle) once its replacement is available, with the sunset date communicated in
advance through the Developer API's documentation/notification channel (Module 31,
Module 21 Notifications) for third-party consumers, and through standard release notes
for first-party clients (which the platform controls the rollout of directly).

**Sunset policy:** Minimum deprecation window before a version is removed: **12 months**
for the Developer API (third parties need lead time to migrate their integrations) and
**until the next mandatory Flutter app store release cycle** for the first-party mobile
API (practically also months, since app store review/rollout is not instantaneous). No
version is removed without an explicit, separate approval — this ADR does not
pre-authorize removal, only defines the minimum notice.

**Error format compatibility:** The standard error response envelope (Bible/Module 31's
"every externally visible error MUST follow the standard error format," §2.23) is
itself versioned as part of the API surface — a change to the error envelope shape is
treated as a breaking change subject to the same major-version rule above, so consumers
can rely on error handling not silently changing under them.

**Version documentation:** Per Module 31 §2.24 ("API documentation MUST be generated
from authoritative source"), each version's documentation is generated from the actual
route/request/response definitions (e.g. via OpenAPI/Swagger generation from Laravel
route+FormRequest+Resource classes), not hand-maintained separately, so docs cannot
drift from behavior.

**Testing requirements:** Every supported major version has its own feature-test suite
run in CI (Step 0.11 and onward); a test asserting `v1` responses remain byte-for-byte
compatible on non-breaking changes (a "contract test") runs on every PR that touches a
versioned endpoint.

## 9. Detailed Implementation Rules

- Route files are organized per version (`routes/api_v1.php`, `routes/api_dev_v1.php`),
  loaded under their respective prefixes — never one flat route file branching on
  version internally, to keep each version's surface auditable in one place.
- Controllers/Resources for a deprecated version are **not** deleted when a new version
  ships; they continue to be maintained (bug/security fixes only, no new features) until
  the sunset date.
- Internal service/domain-layer code is shared across API versions (per Bible's "API is
  a contract, not a shortcut" / Module 31 §2.1–2.2) — only the request/response
  translation layer (Controllers/Resources/FormRequests) is version-specific, so
  business logic is never duplicated per version.

## 10. Alternatives Considered

- **A — Header-based versioning (`Accept: application/vnd.umartechy.v1+json`):**
  Rejected; less discoverable/debuggable for third-party Developer API consumers than a
  visible URL segment, and harder to route cleanly in a modular monolith without extra
  middleware complexity, for no documented benefit over the URL approach.
- **B — Continuous/no versioning (evolve in place, rely only on backward-compatible
  changes forever):** Rejected; directly contradicts Module 31 §2.14–2.15's explicit
  "MUST be explicit and governed" / "MUST require a new major version" requirements.
- **C — Semantic (major.minor.patch) versioning in the URL:** Rejected as unnecessary
  complexity; minor/patch distinctions add no routing value since non-breaking changes
  never require clients to change anything, so only the major number needs to be
  URL-visible.
- **D (Selected) — URL-path major-version-only, dual independent surfaces, as decided
  above.**

## 11. Why Alternatives Were Not Selected

Option A adds discoverability friction for exactly the audience (external developers)
Module 31 is most concerned with serving well. Option B is directly disallowed by the
documentation. Option C adds routing surface area with no corresponding client-visible
benefit, contradicting the explicit "do not introduce unnecessary complexity" framing
instruction for this ADR.

## 12. Security Implications

Versioning by URL prefix makes it straightforward to apply different, explicit
authentication/rate-limit middleware stacks per surface (reinforcing ADR-002), and makes
it easy to audit exactly which versions/surfaces are publicly reachable at any time.

## 13. Multi-Tenant Implications

Tenant resolution (ADR-001) is identical across all versions/surfaces — versioning is a
routing/contract concern only and never changes how tenant context is resolved or
enforced.

## 14. Database Implications

None directly; however, event payload shapes (ADR-004) follow the same "additive change
= no version bump, breaking change = new version" discipline where those payloads are
also exposed via webhooks to Developer API consumers.

## 15. API Implications

This ADR **is** the API-implications document for the platform; it governs every route
file created from Phase 2 (first commerce endpoints) onward, and is a prerequisite
before any public-facing route is written.

## 16. Testing Implications

Per-version contract tests (see §8) and deprecation-header presence tests once a version
is marked deprecated.

## 17. Operational Implications

Version usage (which clients call which version) should be observable (Module 24) so
the sunset decision for a deprecated version is evidence-based, not guessed.

## 18. Scalability Implications

Independent versioning of the two surfaces (first-party vs Developer API) lets the
platform evolve the Developer API (Module 31, a Premium/Enterprise-facing feature) at
its own pace without forcing changes on the core storefront/admin client, and vice
versa.

## 19. Migration / Rollout Considerations

`v1` of the core API is required starting Phase 2 (Commerce Engine — first real
endpoints beyond Milestone 0's foundation). `v1` of the Developer API is required
starting Phase 5 (Module 31 implementation). No version-2 work is anticipated or
scaffolded now.

## 20. Consequences

**Positive:** One clear, documented rule for when a version bump is needed; independent
evolution of first-party vs third-party surfaces; strong alignment with Module 31's
explicit versioning requirements.

**Negative / trade-offs:** Maintaining two live major versions during any future
deprecation window costs ongoing engineering attention (security/bug fixes on both) —
accepted as the standard, necessary cost of a public API contract with external
consumers.

## 21. Future Reconsideration Conditions

Reconsider if the Developer API (Module 31) later needs GraphQL or a different
API paradigm for specific partner needs — that would be a new ADR extending, not
replacing, this convention for the REST surface.

## 22. Related Project Documents

Module 31 §2.14–2.16, §2.23–2.24 (API versioning, error format, documentation
generation), Module 27 (Extension/Marketplace API versioning), Module 30 §109 (Super
Admin API Versioning references); Approved Technology Stack (Milestone-0 document —
"Laravel REST API, Versioned API architecture"); ADR-002 (Authentication Boundary, for
the route-prefix separation this ADR relies on).
