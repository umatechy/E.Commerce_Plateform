# Phase B3 — Step 1: Inspection + Scope Decision (Catalog: Modules 06 & 07)

## Inspection of Existing Code

No catalog code exists yet (B0–B2 deliberately excluded it, per each milestone's
"Scope Control" section). What B3 CAN reuse directly:

- `BelongsToTenant` (ADR-001 Layer 3) — applied to every new tenant-owned model below.
- `EntitlementService::assertCanUse($featureKey, $usageKey)` (B2) — this is Module 06
  §52–53's "Package Limits / Limit Enforcement" integration point, already built and
  tested; B3 is its first real caller.
- `RolePolicy`'s pattern (permission-key check + Owner bypass) — reused verbatim for
  every new Policy below, no new authorization pattern invented.
- `PermissionSeeder` — extended additively with the new permission keys this milestone
  needs (`products.delete`, `products.view_cost`, `categories.manage`, `brands.manage`,
  `attributes.manage`), never removing or renaming existing keys.

## Scope Decision (documented, not silently narrowed)

Modules 06 (102 sections) and 07 (48+ sections) describe a very large surface —
bulk import/export, AI product foundation, catalog snapshots, store cloning, search
index integration, sales channels, product duplicate detection, and more. Building all
of it in one milestone would violate the same "do not attempt every advanced feature
before the foundation is sound" discipline every prior milestone has followed.

**B3 implements the core catalog engine**: Category (hierarchical), Brand, Attribute +
AttributeValue, Product (Simple + Variable types; Digital/Service/Bundle types are
schema-ready via the `ProductType` enum but have no type-specific behavior yet),
ProductVariant, tenant isolation, package-limit enforcement (`max_products`), soft
delete, and server-authoritative pricing (cost price permission-gated, never exposed to
customers).

**Explicitly deferred, listed so nothing is silently dropped:**
- Bulk product operations, import/export (§46–51) — needs the background-job
  infrastructure Module 06 itself describes as a dedicated concern.
- Product search index / Meilisearch integration (§54–55) — Scout is configured
  (`database` driver) but not wired to any model in B3; no storefront search exists
  yet to consume it.
- Cache invalidation for catalog reads (§56) — no public storefront catalog endpoint
  exists yet (Module 05 Storefront is a later phase) to cache in the first place.
- Catalog events on the outbox (§57) — same reasoning as B2's deferred subscription
  events: no consumer exists yet.
- AI product foundation (§77–78), catalog analytics (§79), readiness score (§90),
  duplicate detection (§92), catalog snapshots (§96), store cloning (§98), sales
  channels (§71–72), locale/RTL catalog (§101–102).
- Variant auto-generation from option combinations (§10) — B3 supports variants as a
  direct CRUD resource; generating all combinations from a Cartesian product of
  attribute values is deferred as a dedicated, resource-abuse-guarded feature.
- Concurrent editing / autosave (§66–67) — an admin UX concern for a richer frontend
  than B3 builds.

None of these are abandoned — they are explicitly named so a future milestone's Step 1
inspection finds a documented list, not a silent gap.
