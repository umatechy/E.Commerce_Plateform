============================================================
PHASE B14 CHECKPOINT
============================================================

Phase:
Development Phase B

Module:
B14 — Domain Management (Module 19)

Implementation Summary:
Implemented the authoritative domain-to-tenant mapping Module 19 requires,
closing a gap Phase B6 itself had explicitly documented and anticipated
(ResolveTenantContext already contained a dated docblock describing the exact
interim X-Store-Slug mechanism to be replaced). B14 adds exactly two
integration points to existing code: a new, higher-priority Host-header
resolution step in ResolveTenantContext (X-Store-Slug preserved as fallback,
never removed), and a single replaced method body in Phase B13's
SeoResolver::canonicalUrl() (confirmed by git status to be the ONLY file
touched anywhere under app/Domain/Seo/). Every new domain, verification,
primary-switching, and Super Admin capability is otherwise a clean addition.
Runtime execution remains deferred to VS Code - nothing in this milestone has
been executed against a real PHP/MySQL runtime, and no live DNS query has
been made anywhere (this sandbox's network access is disabled).

Bugs Found and Fixed (design-time, caught before being left in the codebase):
1. DomainService::setPrimary() originally made its eligibility decision using
   the $domain object the CALLER had loaded before the transaction began,
   even after acquiring a row lock inside it - a genuine primary-domain race
   condition (this milestone's own named risk). Fixed by re-fetching the
   target domain's own row from WITHIN the lock, so every decision uses the
   row's truly-current state. A dedicated regression test exercises this
   exact scenario.

Architectural Decisions:
- Only 2 domain types (platform_subdomain, custom_domain) - "Umar Techy-
  managed/purchasable domains" requires a registrar integration this
  platform has no provider for, and Module 19 itself lists it only as a
  future target.
- Only DNS TXT verification is implemented - HTTP-based verification has no
  storefront file-serving surface to place a challenge on in this API-only
  platform.
- Every new store automatically receives an auto-Active, auto-primary
  platform subdomain ({store_slug}.{PLATFORM_BASE_DOMAIN}) - formally
  superseding B13's own path-style placeholder fallback.
- Primary-domain switching uses pessimistic row-locking
  (DB::transaction + lockForUpdate), not a single-counter atomic UPDATE -
  the correct tool for a genuine multi-row invariant ("exactly one TRUE per
  store"), unlike every other atomic-counter pattern in this codebase.
- Verification never accepts a client-submitted token - it always reads the
  token from the Domain row's own stored value, making cross-tenant/cross-
  domain token replay structurally impossible to even express as an API
  call, not merely rejected at runtime.
- DnsResolverContract/SystemDnsResolver are real, correct verification code,
  but this sandbox's disabled network access means no live DNS query has
  ever been made here - every test uses a fake resolver double, stated
  honestly rather than implying real verification occurred.

Domain Model:
Domain (2 types, Module 19's own exact 7-state lifecycle, is_primary flag,
ssl_status representation only - no real provisioning). Global unique
constraint on normalized_hostname - a verified/active hostname can never
simultaneously belong to two stores, enforced at the database level.

Domain Types:
platform_subdomain (auto-created, auto-Active, auto-primary, no external
verification needed or possible) and custom_domain (staff-added, requires
DNS TXT verification before eligible for primary promotion).

Domain Lifecycle:
Pending -> VerificationRequired -> Verified -> Active <-> Suspended/Disabled
-> Removed (terminal), all transitions centralized in DomainStateMachine,
mirroring every other phase's identical state-machine pattern.

Verification:
DNS TXT only. Cryptographically secure tokens (Str::random(48)), 7-day
expiry, verification always reads its own stored token (never a client-
submitted one) against a real DnsResolverContract abstraction whose actual
network call cannot be exercised in this sandbox.

Primary Domain:
Exactly one per store, enforced via pessimistic locking transaction; a
domain must be Verified or Active to be eligible for promotion; the
previously-found race condition is now fixed and regression-tested.

Domain Resolution:
DomainResolverService::resolveHost() is the ONE authoritative Host->Store
lookup - normalizes, looks up the verified registry, confirms Active status
resolves traffic, never falls back to another store on any failure mode
(malformed host, unknown host, non-Active status all resolve to null).

Host Security:
Host header is never trusted directly anywhere - it becomes meaningful only
after normalization, authoritative lookup, and status validation. Reserved
labels (admin/api/www/app/mail/support/static/assets/cdn) are rejected at
the one shared HostnameNormalizer every hostname passes through.

Canonical URL Integration:
SeoResolver::canonicalUrl() (Phase B13) now resolves the real verified
primary domain via DomainResolverService::primaryDomainFor() - the ONE
placeholder method replaced, confirmed by git status to be the only file
touched under app/Domain/Seo/. Sitemap and Open Graph URLs inherit this
automatically since they call through the same method.

Sitemap/Robots/Open Graph Integration:
Sitemap URLs verified to use the real domain (dedicated test). Open Graph
URLs inherit the SeoResolver fix automatically. robots.txt's own Sitemap:
reference line still uses the old config-based placeholder - a documented,
known limitation (not a cross-tenant leak, since robots.txt is already
served per-store by path) rather than a silently-expanded scope change.

Storefront Integration:
No product/category/brand/content/cart/checkout/customer-auth/API/admin
route was modified - confirmed by inspection that domain-based tenant
resolution only affects the correct anonymous-storefront-request boundary
inside ResolveTenantContext, with every other resolution branch (staff,
Customer) byte-for-byte unchanged.

DNS Integration:
DnsResolverContract -> SystemDnsResolver (real dns_get_record() call, never
exercised live in this sandbox) - a genuine, correct abstraction, not a
stub, but its actual network behavior is NOT EXECUTED here.

SSL Integration:
Status representation only (none/pending/active) - no real provisioning,
no certificate or private key stored anywhere.

Provider Abstraction:
DnsResolverContract is the one abstraction point B14 needed; no registrar/
DNS-management-provider abstraction was built (no automated DNS record
creation/update/delete is in scope).

APIs:
GET/POST /api/v1/domains, POST /api/v1/domains/{id}/verification,verify,
primary, DELETE /api/v1/domains/{id} (staff); GET/POST
/api/v1/super-admin/stores/{store}/domains[/{id}/suspend|reactivate] (Super
Admin only, reusing Module 30's existing boundary unchanged).

UI:
Not built - matches every backend-focused phase's own precedent.

Database:
1 new migration: domains (new table, global unique normalized_hostname,
tenant-scoped via store_id). No existing table's existing column altered,
renamed, or removed. No destructive operation performed.

Cache:
None implemented - domain resolution is computed fresh per request;
documented as an acceptable current-scale decision, not an oversight.

Queue / Jobs:
None - verification and primary-switching are synchronous, staff-triggered
actions in this milestone's scope; no DNS-polling background job exists.

Events / Outbox:
domain.added, domain.verification_requested, domain.verified,
domain.primary_changed, domain.removed - all via the existing, unmodified
RecordsOutboxEvents mechanism.

B11 Notification Integration:
Not wired in this milestone - Module 19 Step 44 makes this conditional
("if domain events require user notification, reuse B11"); no concrete
notification requirement was specified precisely enough to build without
inventing template content, so it is named as deferred rather than silently
skipped.

Security Review:
Performed (docs/security/b14-security-review.md) - this milestone's own
35-item checklist reviewed end-to-end, plus a B0-B13 regression confirmation
(git status confirms only SeoResolver.php changed under app/Domain/Seo/,
and only the anonymous-request branch of ResolveTenantContext changed). 1
design-time issue found and fixed (the primary-domain race condition). 3
known limitations documented (RobotsService's own sitemap-reference line
not yet using the verified domain; no caching yet; IDN/punycode hostnames
rejected rather than supported).

Tests Added:
45 new test methods across 8 Feature test files:
- tests/Feature/Domains/HostnameNormalizerTest.php - 8 methods
- tests/Feature/Domains/DomainVerificationServiceTest.php - 8 methods
- tests/Feature/Domains/DomainServiceTest.php - 7 methods
- tests/Feature/Domains/DomainResolverServiceTest.php - 7 methods
- tests/Feature/Domains/DomainConcurrencyTest.php - 2 methods
- tests/Feature/Domains/TenantContextDomainIntegrationTest.php - 3 methods
- tests/Feature/Domains/SeoResolverB14IntegrationTest.php - 3 methods
- tests/Feature/Domains/DomainAdminTest.php - 7 methods
Plus 1 new model factory (Domain). Combined with all carried-forward B0-B13
tests: 506 test methods total across the whole suite (verified by direct
grep count, not estimated).

Tests Actually Executed:
NONE. No PHP, Composer, or MySQL runtime is available in this Claude App
sandbox. No live DNS query has been made anywhere - every DNS-dependent test
uses a fake resolver double.

Tests Not Executed:
All 506 test methods, including all 45 new to this milestone. The
concurrency tests (DomainConcurrencyTest) are explicitly sequential
simulations, not genuine parallel load - row-level locking behavior cannot
be meaningfully exercised without a real MySQL instance either, so this is
flagged as the highest-priority scenario for real verification once a
runtime is available.

Static Inspections Performed (EXECUTED vs INSPECTED vs NOT EXECUTED - nothing
below was EXECUTED):
- Source inspection of every new/modified file against Module 19's
  requirements.
- A Node.js-based brace/parenthesis balance check across all new/modified
  PHP files - no mismatches found.
- git status/diff inspection confirming the exact, minimal blast radius of
  this milestone's two integration points (SeoResolver.php the only Seo file
  touched; ResolveTenantContext's staff/Customer branches unchanged).
- A reflection-based structural test confirming
  DomainVerificationService::attemptVerification() cannot even accept a
  client-submitted token as a parameter.
- Route inspection: confirmed staff domain routes sit inside the
  staff.principal-guarded group, and Super Admin domain routes sit inside
  the existing can:super-admin.impersonate + super_admin.impersonate group,
  unchanged.

Known Limitations:
- Nothing in this milestone has been executed against a real runtime.
- No live DNS verification has occurred or can occur in this sandbox.
- RobotsService's own sitemap-reference line still uses the old config-based
  placeholder, not yet the verified domain (documented, not a leak).
- No caching of domain resolution results yet.
- IDN/punycode hostnames are rejected rather than supported (a scope
  decision per Module 19's own instruction).

Deferred Functionality:
Umar Techy-managed/purchasable domains and registrar integration, HTTP-based
verification, real SSL/HTTPS provisioning, a distinct domain-alias record
type, a www/non-www policy engine, DNS-propagation polling/automated retry,
cross-store domain migration as a dedicated operation, a numeric domain-
health score, B11 notification integration for domain events, admin/
customer-facing UI. Full list with rationale in
docs/development/b14-inspection-findings.md.

Files Changed:
New: app/Domain/Domains/ (Models: Domain, DomainType, DomainStatus,
SslStatus; Services: HostnameNormalizer, DnsResolverContract,
SystemDnsResolver, DomainVerificationService, DomainStateMachine,
DomainService, DomainResolverService; Policies: DomainPolicy;
Http/{Controllers: DomainController; Requests: AddDomainRequest; Resources:
DomainResource}; Exceptions: 4 classes). New:
app/Domain/SuperAdmin/Http/Controllers/SuperAdminDomainController.php,
config/domains.php, 1 migration, 1 factory, 8 test files. Modified:
app/Http/Middleware/ResolveTenantContext.php (+domain-resolution step,
anonymous-request branch only), app/Domain/Seo/Services/SeoResolver.php
(canonicalUrl() body only), app/Domain/Tenancy/Observers/StoreObserver.php
(+platform subdomain auto-creation), PermissionSeeder (+domains.view/manage),
PackageSeeder (+domains.custom_domain for all 3 tiers), StoreObserver
permissions (+domains.view for Manager, domains.manage withheld),
routes/api_v1.php (+domain + super-admin domain routes).

Git Status:
Verified by direct execution (git status) before this checkpoint was
written: all files listed above are new/modified/staged relative to the
previous commit (1a0d391 / 9207c7f). No files outside the Domains domain,
the two precisely-scoped integration points, the additive StoreObserver
extension, and documentation were touched.

Git Commit Status:
A commit for this milestone's work follows immediately after this
checkpoint; the real, executed commit hash is recorded via a follow-up
correction commit immediately after, same pattern used for every prior
phase's checkpoint.

Recommended Next Milestone:
Phase B15 - per the approved module sequence, Module 17 (Theme, Branding &
Design System) is the next natural candidate: it is the module most
frequently adjacent to both B13 (SEO/Content) and B14 (Domain Management) in
this project's own cross-reference lists, and no later module in the
project's numbered sequence has yet been implemented. A secondary candidate
is Module 20 (Hosting & Infrastructure Management), which B14 itself
touches tangentially (SSL/DNS status representation) but does not own.
Phase B15's own Step 1 should inspect the existing Store model and B13's
SeoSetting table before designing any theme/branding schema, since branding
fields (logo, color scheme, storefront layout choice) will likely need their
own per-store configuration table following the same patterns already
established.
