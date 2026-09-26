# Phase B14 — Focused Domain Management Security Review

Static/design-level review only — **NOT EXECUTED — DEFERRED TO VS CODE RUNTIME
VERIFICATION**. No live DNS query has been made anywhere in this milestone
(this sandbox's network access is disabled) — every DNS-dependent test uses a
fake resolver double.

## Regression Check — B0-B13 Capabilities Confirmed Intact

Verified by direct inspection and `git status`: `BelongsToTenant::store()`
present; `ResolveTenantContext`'s staff (`activeStoreId()`) and Customer
(`Customer::store_id`) resolution branches are byte-for-byte unchanged — only
the anonymous-request branch gained a new, higher-priority step, with
`X-Store-Slug` preserved exactly as the fallback; `git status` confirms
`SeoResolver.php` is the ONLY file modified anywhere under `app/Domain/Seo/`
— every other B13 service and controller is untouched.

## Checklist (this milestone's own 35-item Step 45 list)

| # | Item | Finding | Status |
|---|---|---|---|
| 1 | Host header poisoning | `DomainResolverService::resolveHost()` never trusts the Host header directly — it is normalized, then looked up against the authoritative `Domain` registry, and only an `Active`-status match ever resolves a store. A malformed or unmatched Host resolves to `null`, never a guessed/default store. Tested explicitly. | Reviewed — OK |
| 2 | Tenant confusion | Global unique constraint on `normalized_hostname` makes it impossible for two `Domain` rows across different stores to share a hostname at all. | Reviewed — OK |
| 3 | Domain takeover | Only DNS TXT verification (proof of DNS-zone control) promotes a domain past `VerificationRequired`; ownership is never inferred from merely adding the hostname. | Reviewed — OK |
| 4 | Verification token guessing | `Str::random(48)` — cryptographically secure, never derived from store id/domain/timestamp/email. Tested (two domains never share a token). | Reviewed — OK |
| 5 | Verification token replay | `attemptVerification()` takes only a `Domain` model — there is no parameter for a client to submit a token to compare against at all; it always reads the token from the row's own stored value. Verified via a reflection-based structural test, not just a runtime check. | Reviewed — OK |
| 6 | Cross-tenant domain access | `DomainPolicy::view()`/`manage()` check `belongsToUsersActiveStore()` before any operation; tested explicitly (403 setting another store's domain as primary). | Reviewed — OK |
| 7 | Cross-tenant domain assignment | A `Domain` row's `store_id` is set once at creation (`addCustomDomain($store, ...)`), never accepted as client input on any subsequent operation. | Reviewed — OK |
| 8 | Unauthorized primary-domain change | `DomainPolicy::manage($user, $domain)` re-checks tenant ownership per-domain, not just per-store-generically. Tested explicitly. | Reviewed — OK |
| 9 | Domain enumeration | `Domain` uses `public_id` (ULID) for all client-facing addressing; staff routes are tenant-scoped by `BelongsToTenant`'s global scope, so even guessing another store's internal id returns 404 (route-model-binding + scope). | Reviewed — OK |
| 10 | Open redirects | B14 introduces no redirect functionality of its own (Phase B13's `RedirectService` is untouched and out of scope here). | N/A — no new surface |
| 11 | Redirect loops | Same as #10. | N/A — no new surface |
| 12 | Canonical URL poisoning | `SeoResolver::canonicalUrl()` builds the canonical URL from `DomainResolverService::primaryDomainFor()` — a value resolved entirely server-side from the verified `Domain` registry, never from any request input. | Reviewed — OK |
| 13 | Sitemap poisoning | `SitemapService` (Phase B13, unchanged) calls the same `SeoResolver` methods — inherits the real-domain fix automatically; tested explicitly. | Reviewed — OK |
| 14 | Robots poisoning | `RobotsService` (Phase B13, unchanged) builds its sitemap reference from `config('seo.storefront_base_url')` still — see Known Limitations below; not yet switched to the verified domain. | Documented limitation — see below |
| 15 | Open Graph poisoning | Open Graph URLs are built by `ResolvedSeoResource`, which reads `ResolvedSeo::canonicalUrl` — inherits the same fix as #12. | Reviewed — OK |
| 16 | Cache poisoning | No domain resolution result is cached anywhere in B14 (resolved fresh per request) — trivially satisfies tenant-cache-isolation by not caching yet. | N/A this milestone |
| 17 | DNS configuration abuse | `SystemDnsResolver` only ever READS TXT records (`dns_get_record()`) — B14 contains no code path that writes/modifies any DNS configuration anywhere. | Reviewed — OK, N/A by construction |
| 18 | Provider credential exposure | No DNS/registrar provider credential of any kind exists in this codebase (none is needed — only a read-only system DNS lookup). | N/A — no credential exists |
| 19 | SSL secret exposure | `Domain.ssl_status` is a plain status enum, never a certificate or private key; no SSL secret of any kind is stored anywhere. | Reviewed — OK, N/A by construction |
| 20 | Reserved domain abuse | `HostnameNormalizer::assertNotReserved()` checked before every `addCustomDomain()`/`createPlatformSubdomain()` call — the reserved-label check cannot be bypassed since it lives inside the one normalization method every hostname passes through. | Reviewed — OK |
| 21 | IDN/punycode confusion | Not supported — `HostnameNormalizer`'s regex rejects any hostname containing non-ASCII characters (they fail the `[a-z0-9-]` character class), so an IDN/homograph-shaped hostname is rejected outright rather than silently mishandled. | Reviewed — OK (rejected, not attempted) |
| 22 | Homograph domain risks | Same as #21 — rejection at validation time removes this risk category entirely rather than attempting unsafe Unicode comparison logic. | Reviewed — OK |
| 23 | Malformed hostname handling | 8 dedicated `HostnameNormalizerTest` cases cover full-URL, IP address, malformed-syntax, and various valid/edge inputs. | Reviewed — OK |
| 24 | Public/private domain confusion | Not applicable in B14's scope — no distinction between "public" and "private" domain visibility exists or is claimed (every `Domain` row is either resolving traffic or it isn't, per its status). | N/A — concept not in scope |
| 25 | Suspended domain routing | `DomainStatus::resolvesTraffic()` returns `false` for every status except `Active` — a `Suspended` domain's Host header resolves to `null`, never falls through to serving the store anyway. Tested explicitly. | Reviewed — OK |
| 26 | Deleted domain routing | Same mechanism as #25 — `Removed` also fails `resolvesTraffic()`. Tested explicitly (a Removed domain's former hostname never resolves to its old store). | Reviewed — OK |
| 27 | API authorization | `DomainPolicy` checked in every staff controller method; Super Admin routes reuse the existing `can:super-admin.impersonate` + `super_admin.impersonate` middleware chain unchanged. Tested explicitly (403/401 cases). | Reviewed — OK |
| 28 | Super Admin boundary | `SuperAdminDomainController` performs no authorization of its own — it relies entirely on the pre-existing, already-reviewed middleware chain, the same pattern as `SuperAdminStoreController`/`SuperAdminSubscriptionController`; an ordinary store owner cannot reach these routes (tested explicitly, 403). | Reviewed — OK |
| 29 | Entitlement bypass | `domains.custom_domain` checked via `EntitlementService::assertFeatureEntitled()` before `addCustomDomain()` — the same established B7-B13 pattern. | Reviewed — OK |
| 30 | Queue tenant spoofing | B14 introduces no queued job (verification/set-primary are synchronous, staff-triggered actions in this milestone's scope — no DNS-polling job exists to spoof). | N/A — no new job surface |
| 31 | Audit bypass | Domain lifecycle events (`domain.added`, `domain.verification_requested`, `domain.verified`, `domain.primary_changed`, `domain.removed`) are recorded via the existing, unmodified `RecordsOutboxEvents` mechanism — the same audit-adjacent trail every other phase since B5 uses. | Reviewed — OK |
| 32 | Stale domain cache | No cache exists yet (see #16) — nothing to go stale. | N/A this milestone |
| 33 | Primary-domain race condition | The exact bug found and fixed this milestone (see architecture doc) — `setPrimary()` now re-fetches under lock. Dedicated regression test (`DomainConcurrencyTest`). | Reviewed — OK, fixed |
| 34 | Concurrent verification | `attemptVerification()` only ever transitions FROM `VerificationRequired` — a second concurrent call against an already-`Verified` domain fails its own status precondition check rather than re-verifying or double-processing. | Reviewed — OK |
| 35 | Cross-tenant canonical URL generation | `SeoResolver::canonicalUrl()` resolves the domain via `DomainResolverService::primaryDomainFor($store)`, where `$store` is always the SPECIFIC entity's own owning store (`$product->store`, etc.) — there is no code path where one store's entity could resolve using another store's domain. | Reviewed — OK |

## Issues Found and Fixed During Implementation (Design-Time, Not Post-Hoc)

1. `DomainService::setPrimary()`'s stale-in-memory-object race condition (item
   #33 above) — the single most significant finding this milestone, fixed
   before being left in the codebase.

## Known Limitations (Documented, Not Hidden)

1. **`RobotsService` (Phase B13) still references `config('seo.storefront_base_url')`
   for its `Sitemap:` line, not the verified domain** — this was not part of
   this milestone's own explicitly-scoped replacement (only `SeoResolver::canonicalUrl()`
   was named as the one placeholder to replace), and `robots.txt` is served
   per-store from a path already keyed by `{storeSlug}`, so no cross-tenant
   leakage results — but the URL text itself does not yet reflect a verified
   custom domain. Flagged for a small follow-up fix in the next SEO/domain-
   related phase rather than expanding this milestone's scope silently.
2. No caching of domain resolution results yet (acceptable at current scale,
   per the same reasoning as B12/B13's identical decisions).
3. IDN/punycode hostnames are rejected outright rather than supported — a
   scope decision, not a gap, per Module 19 §46's own "if IDN is not
   supported, reject unsupported values clearly" instruction.

None of the above required deleting or resetting existing B0-B13 work. No
destructive database operation was performed (the one new migration in B14 is
new-table only).
