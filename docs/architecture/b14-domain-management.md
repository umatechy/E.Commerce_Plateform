# Phase B14 — Domain Management Architecture (Module 19)

See `docs/development/b14-inspection-findings.md` for the scope decision and
the critical finding: `ResolveTenantContext` (Phase B6) already contained a
dated docblock explicitly acknowledging this exact gap and describing the
interim mechanism to replace.

## The Two Integration Points, Precisely Scoped

B14's entire job is captured in two changes, both minimal and additive:

1. **`ResolveTenantContext`** gains one new, higher-priority anonymous-
   resolution step: the real HTTP `Host` header is checked against the
   `Domain` registry BEFORE falling back to the Phase B6 `X-Store-Slug`
   header, which is preserved exactly, unchanged, for the contexts it was
   introduced for (local development, this sandbox, non-Host-bearing
   clients). Confirmed by inspection: `activeStoreId()`/`Customer` resolution
   paths are byte-for-byte unchanged.
2. **`SeoResolver::canonicalUrl()`** (Phase B13) — its ENTIRE body is replaced
   with a real call to `DomainResolverService::primaryDomainFor()`. Confirmed
   by `git status`: this is the ONLY file touched anywhere under
   `app/Domain/Seo/` — `ContentSanitizer`, `RedirectService`,
   `SitemapService`, `StructuredDataService`, every controller, every model,
   are byte-for-byte unchanged. Sitemap/robots/Open-Graph inherit the real
   domain automatically, since they all call through this one method.

## Domain Model — Two Types, Seven States

`Domain` (`domain_type`: `platform_subdomain` | `custom_domain`; `status`:
Module 19's own exact 7-state list — Pending/VerificationRequired/Verified/
Active/Suspended/Disabled/Removed). Only `Active` ever resolves live storefront
traffic (`DomainStatus::resolvesTraffic()`) — every other status, including
`Verified` (proven ownership but not yet promoted), returns `null` from the
resolver rather than serving anything. `normalized_hostname` carries a GLOBAL
unique database constraint — a verified/active hostname can never
simultaneously belong to two stores, enforced at the database level, not just
application logic.

## Platform Subdomain — Every Store Gets One, Automatically

`StoreObserver` (extended once more, additively — the same established
"seed something for every new store" pattern used by every phase since B1)
now calls `DomainService::createPlatformSubdomain()` for every new store:
`{store_slug}.{PLATFORM_BASE_DOMAIN}`, auto-`Active`, auto-`primary` — no
external verification is needed or possible, since the platform itself
controls this DNS zone. This REPLACES B13's own path-style placeholder
fallback (`{base_url}/{slug}/...`), which is now formally superseded.

## Verification — Real Abstraction, Honestly Labeled as Unexercised

`DomainVerificationService` never accepts a client-submitted token to compare
against — it always reads the token from the `Domain` row's OWN stored value,
which makes "domain_id=B, token=A" structurally impossible to even express as
an API call (verified by a reflection-based test asserting
`attemptVerification()` takes exactly one parameter: the `Domain` model
itself). Tokens are `Str::random(48)`, never derived from any predictable
input. `DnsResolverContract` → `SystemDnsResolver` (calls PHP's real
`dns_get_record()`) is genuine, correct verification code — but this sandbox's
network access is disabled entirely, so **no live DNS query has ever actually
been made or can be, here**. Every test exercises this through a
`FakeDnsResolver` test double instead, and this is stated plainly rather than
implying real verification occurred.

## Primary Domain — Pessimistic Locking, Not a Single-Counter Atomic Update

Unlike B2/B7/B9/B10's single-counter atomic-`UPDATE` pattern (which works for
"decrement one number"), "exactly one primary domain per store" is a genuine
multi-row invariant. `DomainService::setPrimary()` wraps the whole operation
in one `DB::transaction()` with `lockForUpdate()` — demotes every other domain
for that store, then promotes the target, both inside the same lock. A real
concurrency bug was found and fixed here (below).

## Bug Found and Fixed During This Milestone

`DomainService::setPrimary()` originally made its eligibility decision using
the `$domain` object the CALLER had loaded BEFORE the transaction began, even
after acquiring the row lock inside it — a genuine "primary-domain race
condition" (this milestone's own named risk, Step 48/Question #33). Fixed by
re-fetching the target domain's own row (`lockForUpdate()->findOrFail()`) from
WITHIN the lock, so every decision uses the row's truly-current state, not a
possibly-stale in-memory copy. A dedicated regression test
(`DomainConcurrencyTest`) exercises this exact scenario.

## Reserved Domains and Hostname Validation

`HostnameNormalizer` rejects full URLs (never silently strips a scheme/path —
fails loud so an admin who pastes a URL by mistake gets a clear error),
IP addresses, malformed RFC-1035-shaped hostnames, and any hostname whose
first label matches `config('domains.reserved_labels')` (admin/api/www/app/
mail/support/static/assets/cdn).

## Super Admin Domain Oversight — Reuses Module 30 Exactly

`SuperAdminDomainController` sits in the SAME `super-admin` route group
(`can:super-admin.impersonate` + `super_admin.impersonate` middleware) as the
existing `SuperAdminStoreController`/`SuperAdminSubscriptionController` —
TenantContext is already resolved to the target `{store}` by that middleware
before this controller runs; no new Super Admin boundary was built.

## Entitlement

`domains.custom_domain` feature flag (all 3 package tiers) — no numeric
per-store domain-count limit was invented, since Module 19 gives none.
`domains.manage` (add/verify/activate/remove/set-primary) is deliberately
withheld from the default Manager role (Owner-only via `isOwner()`) —
consistent with `analytics.financial`'s identical sensitivity-driven decision
in Phase B12, since changing a store's primary domain affects its entire
public identity.

## API Endpoints Added in B14

| Method | Path | Auth |
|---|---|---|
| GET/POST | `/api/v1/domains` | staff (`domains.view`/`manage`) |
| POST | `/api/v1/domains/{id}/verification` | staff — initiates DNS TXT verification |
| POST | `/api/v1/domains/{id}/verify` | staff — attempts verification |
| POST | `/api/v1/domains/{id}/primary` | staff — atomic primary switch |
| DELETE | `/api/v1/domains/{id}` | staff — soft "Removed" |
| GET/POST | `/api/v1/super-admin/stores/{store}/domains[/{id}/suspend\|reactivate]` | Super Admin only |

## UI

Not built in B14, matching every backend-focused phase's own precedent.

## Deferred (see inspection findings for the full, explicit list)

Umar Techy-managed/purchasable domains and registrar integration, HTTP-based
verification, real SSL/HTTPS provisioning, a distinct domain-alias record type
(any non-primary Active domain already functions as one), a www/non-www
policy engine, DNS-propagation polling/automated retry, cross-store domain
migration as a dedicated operation, a numeric domain-health score, admin/
customer-facing UI.
