# Phase B14 — Step 1: Inspection + Scope Decision (Domain Management: Module 19)

## Inspection of Existing Code — Critical Finding

`ResolveTenantContext` (Phase B6) **already contains an explicit, dated
docblock acknowledging this exact gap**: for anonymous (guest) storefront
requests, it resolves tenant via an interim `X-Store-Slug` header, with a
comment reading *"Module 19 (Domain Management — resolving tenant from the
storefront's own domain/subdomain) is not built yet... this interim mechanism
is used until Module 19 exists."* This is the precise, pre-identified
integration point for B14. Per this milestone's own Step 20/64 ("existing B6
guest-store header behavior must remain limited to the exact contexts where it
was intentionally introduced... do not rewrite B13 SEO unnecessarily... prefer
minimal compatible changes"), B14:

- **Adds** a new, HIGHER-PRIORITY resolution step to `ResolveTenantContext`
  for anonymous requests: the real HTTP `Host` header is checked against the
  authoritative `Domain` registry FIRST.
- **Preserves** `X-Store-Slug` as the fallback for anonymous requests whose
  Host header matches no registered domain (covers local development, the
  Claude App sandbox, and any client — e.g. a mobile app calling the API
  directly — that has no meaningful "Host" of its own) — never removed,
  exactly as instructed.
- Never lets `X-Store-Slug` or any other client-supplied header/parameter
  override a request whose Host header DID match a verified, active domain
  (Non-Negotiable Step 20).

## Other Inspection Findings

- **Module 30 (Super Admin) already exists** (`app/Domain/SuperAdmin/`,
  `EnsureSuperAdminImpersonation` middleware, `SuperAdminAccessPolicy`,
  `User::isPlatformStaff()`) — B14 reuses this exactly for platform-level
  domain oversight (suspend/reactivate any store's domain), rather than
  building a new Super Admin boundary.
- **No live DNS resolution is possible in this sandbox** — this environment's
  network configuration disables outbound network access entirely (see
  `<network_configuration>`: "Enabled: false"). `DnsResolverContract` is
  therefore built as a real, injectable abstraction (`SystemDnsResolver` calls
  PHP's own `dns_get_record()`, which is correct, genuine verification code)
  but **cannot be exercised in this environment** — every test that needs a
  DNS answer uses a `FakeDnsResolver` test double, and this gap is stated
  honestly rather than claiming live verification occurred.
- `Store` (B1/B3) has a `slug` column already; `StoreObserver` (B1, extended
  by B7/B8/B9/B10/B11/B13) is the established pattern for "seed something for
  every new store" — B14 extends it once more, additively, to auto-create each
  new store's platform subdomain `Domain` row.
- `SeoResolver::canonicalUrl()` (Phase B13) currently builds canonical/
  sitemap/robots/Open-Graph URLs from a single configured
  `seo.storefront_base_url` + the store's own slug as a PATH segment — this
  was B13's own explicitly-documented placeholder, pending exactly this
  module. **B14 replaces this one method's body** (and only this one) with a
  real call to the new `DomainResolverService::primaryDomainFor()` — every
  other line of `SeoResolver`, and all of B13's other services
  (`RedirectService`, `ContentSanitizer`, `SitemapService`,
  `StructuredDataService`), are completely untouched.
- No regressions found in B0-B13 during inspection.

## Architectural Decision — Platform Subdomain Pattern (Module 19 §8, "Do Not Invent")

Module 19 itself calls this the "Platform Subdomain System" (a subdomain, not a
path segment) — this is MORE specific than B13's own path-style placeholder
(`{base}/{slug}/...`), so B14 formalizes the real pattern:
**`{store_slug}.{PLATFORM_BASE_DOMAIN}`** (a new `PLATFORM_BASE_DOMAIN` env
value, e.g. `stores.example` — a placeholder value in this sandbox, since no
real wildcard DNS zone is provisioned here; the exact value is a deployment-
time configuration, not a business decision this code needs to hard-code).
Every new store automatically receives one such `Domain` row
(`domain_type = platform_subdomain`), auto-`Active` and auto-`primary`
(the platform itself controls this DNS zone, so no external verification step
is needed or possible) — this REPLACES B13's placeholder path-style fallback,
which is now formally superseded, not merely supplemented.

## Architectural Decision — Domain Types (Module 19 §4, Two Only)

**`platform_subdomain`** (above) and **`custom_domain`** (a customer-owned
hostname, requiring DNS TXT verification before it can resolve traffic).
**"Umar Techy-managed domain"** (Module 19's own third example — implying
actual domain registration/purchase on the customer's behalf) is explicitly
deferred: it requires a registrar/purchasing integration this platform has no
provider account or API credential for, and Module 19 itself only lists it as
a "future automated registrar integration" (§19) target, not a mandatory
current-phase deliverable.

## Architectural Decision — Verification Mechanism (Module 19 §14, DNS TXT Only)

Only **DNS TXT record verification** is implemented (Module 19's own §14/§15
primary mechanism) — HTTP file-based verification (§16, listed as an
alternative) is not built, since no storefront file-serving surface exists in
this API-only platform to place a challenge file on. `DnsResolverContract` is
a real, correct abstraction; `SystemDnsResolver` (the production implementation,
calling `dns_get_record()`) exists in the codebase but its actual network call
**cannot be exercised in this sandbox** (see Inspection Findings above) — this
is stated plainly rather than claiming a verification that never really ran.

## Architectural Decision — Primary Domain Switch Concurrency (Module 19 §11/§17-18)

MySQL has no clean native "at most one TRUE per group" partial-unique-index
syntax portable across this project's target versions, so B14 enforces
"exactly one primary domain per store" at the APPLICATION layer: `DomainService::setPrimary()`
wraps the whole operation in a single `DB::transaction()` with
`lockForUpdate()` on that store's `domains` rows (the same pessimistic-locking
pattern this codebase has not needed before, but is the textbook correct tool
for a genuine multi-row invariant, as opposed to B2/B7/B9/B10's single-counter
atomic-`UPDATE` pattern which does not apply to a "flip exactly one boolean
among several rows" operation) — demotes every other domain, then promotes the
target, all inside one locked transaction, making a race between two
concurrent "set primary" calls resolve deterministically (last-committer-wins,
never two simultaneous primaries).

## Scope Decision (Module 19 spans 63 sections)

**B14 implements**: `Domain` (2 types, 7-state lifecycle, primary flag, SSL-
status representation only — no real provisioning), hostname normalization +
validation + reserved-name protection, DNS-TXT verification (real abstraction,
unexercisable live call, honestly labeled), atomic primary-domain switching,
`DomainResolverService` (the authoritative Host→Store lookup, wired into
`ResolveTenantContext` as a new, higher-priority step ahead of the preserved
`X-Store-Slug` fallback), replacement of B13's one placeholder canonical-URL
method with real domain resolution (sitemap/robots/Open Graph inherit this
automatically since they all call through `SeoResolver`), Super-Admin platform-
level domain oversight (reusing Module 30's existing boundary), staff-facing +
Super-Admin APIs, and `domains.custom_domain` entitlement gating (a boolean
feature flag — no numeric per-store domain-count limit is invented, since none
is given).

**Explicitly deferred** (named so nothing is silently dropped):
- **Umar Techy-managed/purchasable domains, registrar integration (§19)** — no
  registrar provider/credential exists.
- **HTTP-based verification (§16)** — no storefront file-serving surface
  exists to place a challenge on.
- **Real SSL/HTTPS provisioning (§17-18)** — only a status ENUM
  (`none`/`pending`/`active`) is stored; no Let's Encrypt/ACME or equivalent
  integration exists, and none is claimed.
- **Domain aliases as a distinct concept from multiple Active domains (§12)** —
  any non-primary `Active` domain already resolves traffic to the same store,
  which is functionally an alias; a separate "alias" record type is not built
  since it would duplicate this.
- **www/non-www policy engine (§13)** — `wwwPolicy` is not a separate,
  configurable per-domain setting; `www.` is treated as a distinct hostname
  requiring its own `Domain` row (documented, simple default), not
  auto-redirected.
- **DNS propagation polling/automated retry scheduling (§16)** — verification
  is a single, staff-triggered check (`POST .../verify`), not a background
  poller; this is consistent with "do not claim live DNS verification" and
  avoids building a scheduled job around a network call this sandbox cannot
  make at all.
- **Domain migration between stores (§14 "Support domain migration")** — no
  concrete migration workflow is specified beyond "support it"; removing a
  domain from one store and adding it fresh to another (going through full
  re-verification) already achieves the safe outcome without inventing a
  dedicated "transfer" operation.
- **Domain health scoring beyond the individual status/verification/SSL
  fields themselves (§36's own Non-Negotiable against inventing a numeric
  score)** — honored by not building one.
- Admin/customer-facing UI (React components) — matches every backend-focused
  phase's own precedent.

None of these are abandoned — each is named so Phase B15+'s own Step 1
inspection finds this documented list.

## Bug Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

`DomainService::setPrimary()` originally used the `$domain` model instance the
CALLER had already loaded (before the transaction began) to decide whether a
status transition to `Active` was needed, even after acquiring
`lockForUpdate()` on the store's `domains` rows inside the transaction. If a
concurrent request had changed that same domain's status between the caller's
original load and this method's lock acquisition, the decision would have been
based on a stale in-memory value rather than the row's true current state —
exactly the "primary-domain race condition" this milestone's own Step 48/Final
Inspection Question #33 calls out. Caught while writing the concurrency test,
before being left in the codebase — fixed by re-fetching the target domain's
own row (`Domain::query()->lockForUpdate()->findOrFail()`) from WITHIN the
lock, so every subsequent decision in the method uses the truly-current state.
