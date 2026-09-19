============================================================
PHASE B3 CHECKPOINT
============================================================

Phase:
Development Phase B

Milestone:
B3 — Catalog (Product & Category Management — Modules 06 & 07)

Status:
Implemented in Claude App environment as far as this environment allows. Runtime
execution deferred to VS Code phase (no PHP/Composer/MySQL/Redis/network available
here — unchanged since Milestone-0 preflight).

Completed:
- Step 1 inspection + explicit scope decision performed before any code
  (docs/development/b3-inspection-findings.md) — Modules 06/07 span 150+ sections;
  B3 implements the core catalog engine and explicitly lists every deferred area
  rather than silently narrowing scope.
- Category (hierarchical, parenting-rule-validated), Brand, Attribute +
  AttributeValue (normalized), Product (Simple + Variable types with real behavior),
  ProductVariant — all tenant-owned, all using BelongsToTenant.
- Server-authoritative, permission-gated cost pricing (Module 06 §25) — never exposed
  without explicit authorization, built conditionally into the API response, not
  merely hidden by convention.
- Package-limit enforcement (Module 06 §52-53) wired as EntitlementService's first
  real caller (built in Phase B2, unused until now) — create, archive/unarchive, and
  delete all correctly increment/release max_products usage.
- Found and fixed a real cross-tenant data-association gap: Laravel's `exists:`
  validation rule has no tenant filter, which would have let a Store A request
  reference Store B's brand/category ID. Closed via explicit tenant-scoped
  re-validation.
- Found and fixed a code-duplication issue (isOwner() logic, moved from being
  duplicated per-Policy into the shared BaseTenantPolicy base class).
- 4 new Policies, 5 new Controllers, 6 FormRequests, 5 API Resources, 7 new
  migrations (all additive/new-table, no destructive change).
- 26 new test methods across 4 Feature test files covering CRUD, tenant isolation,
  package-limit enforcement, cost-price gating, and category hierarchy rules.
- Focused security review performed; 2 issues found and fixed, 3 documented for
  later modules.
- Documentation created across docs/development/, docs/architecture/,
  docs/security/, docs/checkpoints/.

Category:
Self-referencing hierarchy (parent_id), tenant-owned. Parenting rules (Module 07 §8:
no self-parent, no cycles, same-tenant-only) validated in
CategoryController::assertValidParent() before every create/update — tested
explicitly for all three violation types.

Brand / Attribute:
Brand — simple tenant-owned entity. Attribute + AttributeValue — tenant isolation
inherited from attributes.store_id (no separate scope needed on AttributeValue, same
pattern as permission_role from Phase B1). normalized_value prevents case-duplicate
values (Module 07 §37), enforced by a database unique constraint.

Product:
Core catalog entity (Module 06 §5). Money as integer minor units + currency
(ADR-003, unchanged convention). ProductType schema-ready for all 5 initial types;
Simple and Variable have real behavior in B3. Soft-deleted (Module 06 §32 historical
order integrity, ready for Phase B5 Orders).

Package Limits:
EntitlementService::assertCanUse('products.basic', 'max_products') gates product
creation; assertWithinLimit() gates un-archiving. recordUsage()/releaseUsage() are
called ONLY from ProductController, verified by inspection. A documented (not
hidden) known limitation: the create-then-record sequence has a small race window
distinct from B2's atomic counter increment — accepted as a low-severity commercial
trade-off, not a security issue.

Tenant Isolation:
Product/Category/Brand/Attribute/ProductVariant all use BelongsToTenant. A real gap
was found and fixed this milestone: exists:brands,id / exists:categories,id
validation rules have no tenant filter on their own — ProductController now
re-validates brand_id/primary_category_id/category_ids against tenant-scoped
queries before any product is created or updated. 7 dedicated tenant-isolation test
methods added.

Authorization:
ProductPolicy, CategoryPolicy, BrandPolicy, AttributePolicy — all extend
BaseTenantPolicy, all registered explicitly in AppServiceProvider. isOwner() moved
into the shared base class this milestone (was duplicated in RolePolicy, would have
been duplicated 4 more times otherwise). ProductVariant has no separate Policy —
authorized via its parent Product's Policy by design.

Super Admin:
No new Super Admin surface needed for B3 (catalog management is entirely
store-scoped, not a platform-level concern) — none added, none needed.

API:
GET/POST/PUT/DELETE /api/v1/products[/{product}], nested
/api/v1/products/{product}/variants[/{variant}], /api/v1/categories[/{category}],
/api/v1/brands[/{brand}], and /api/v1/attributes[/{attribute}] (index/store/destroy
only — update deferred, not needed for B3's core scope). Every endpoint validated,
authorized, tenant-scoped, safe response serialization.

Frontend:
None added this milestone — B3's scope (per the established "do not build every
admin screen" discipline from Phase B1) was the server-side catalog engine and API;
a Catalog admin UI is deferred to a dedicated frontend pass once Inventory (Phase
B4) and Orders (Phase B5) exist, so the admin product form can show stock/order data
alongside catalog fields rather than being built twice.

Events:
No new outbox events wired (consistent with B2's precedent — no consumer exists yet
that needs catalog changes asynchronously; Module 06 §57's catalog events are a
documented deferred item, not silently dropped).

Cache:
Not implemented this milestone — no public storefront catalog read path exists yet
to cache (Module 05 Storefront is a later phase); documented as deferred, not
silently omitted.

Database:
7 new migrations (categories, brands, attributes, attribute_values, products,
product_variants, product_category) — all additive new-table migrations, no existing
table altered, no destructive operation performed.

Tests Created:
26 new test methods across 4 Feature test files:
- tests/Feature/Catalog/ProductTest.php — 8 methods
- tests/Feature/Catalog/CategoryTest.php — 7 methods
- tests/Feature/Catalog/CatalogTenantIsolationTest.php — 7 methods
- tests/Feature/Catalog/BrandAndAttributeTest.php — 4 methods
Plus 4 new model factories (Product, Category, Brand, Attribute). Combined with all
carried-forward B0/B1/B2 tests: 94 test methods total across the whole suite
(verified by direct grep count, not estimated).

Tests Executed:
NONE.

Runtime Verification:
NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION. No PHP, Composer, MySQL, or
Redis runtime is available in this Claude App sandbox; no outbound network access to
Packagist either. Every test, all 7 new migrations, PHPStan, ESLint, npm build, and
the CI workflow itself have been authored and statically reasoned about, never
executed. A lightweight Node.js-based brace-balance check was run across all new/
modified PHP files as an additional (non-substitute) sanity pass — no mismatches
found.

Security Review:
Performed (docs/security/b3-security-review.md) — 14-item checklist reviewed
end-to-end. 2 issues found and fixed this milestone (cross-tenant brand/category
assignment via unscoped exists: validation; duplicated isOwner() logic). 3 items
documented as correctly deferred (package-limit race window as an accepted
trade-off, client-price-trust boundary flagged for Phase B7, search/cache/events
flagged for their respective future consumers).

Documentation:
docs/development/b3-inspection-findings.md, docs/architecture/b3-catalog.md,
docs/security/b3-security-review.md, this checkpoint. Project Bible, SRS, and ADR
status were not modified.

Known Limitations:
- Nothing in this milestone has been executed against a real runtime.
- Package-limit create-then-record sequence has a documented small race window
  (see Package Limits above) — accepted trade-off, not a hidden gap.
- No variant auto-generation from attribute option combinations (Module 06 §10) —
  variants are created individually via the API in B3.
- No catalog admin frontend UI — deferred until Inventory/Orders exist (see
  Frontend above).
- Digital/Service/Bundle product types have no type-specific fields or workflow yet
  (schema-ready only).

Deferred VS Code Verification:
1. composer install / npm install.
2. php artisan migrate (7 new migrations, on top of B0/B1/B2's).
3. php artisan test — all 94 test methods, for real PASS/FAIL results.
4. composer stan, npm run lint, npm run build.
5. Manual verification of the category-cycle-prevention logic against a real
   multi-level hierarchy in MySQL.

Next Milestone:
Phase B4 — Inventory (Module 08), per the approved milestone map. B3 exit criteria
are met: core catalog engine exists and is tenant-safe; package-limit enforcement is
real and tested; server-authoritative pricing with permission-gated cost visibility
exists; no catalog-specific codebase-per-package exists; required API and tests
exist; security review and documentation are complete. Phase B4's own Step 1 will
need to inspect how ProductVariant's implicit stock concept (none currently — B3 has
no inventory/stock fields at all, by design, since Module 08 owns that) integrates
with the Product/ProductVariant models built here, before any Inventory code is
written.
