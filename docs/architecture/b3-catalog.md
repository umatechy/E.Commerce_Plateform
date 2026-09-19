# Phase B3 — Catalog Architecture (Modules 06 & 07)

See `docs/development/b3-inspection-findings.md` for the explicit scope decision (what
is and is not built this milestone).

## Entities

- **Category** — self-referencing hierarchy (`parent_id`), tenant-owned. Parenting
  rules (no self-parent, no cycles, same-tenant-only — Module 07 §8) are validated in
  `CategoryController::assertValidParent()`, not just via the FK constraint, because
  "no circular hierarchy" is a graph property a single FK cannot express.
- **Brand** — simple tenant-owned catalog entity (Module 07 §21-26).
- **Attribute + AttributeValue** — tenant isolation inherited from `attributes.store_id`
  (no separate scope on `AttributeValue`, same pattern as `permission_role`).
  `normalized_value` (Module 07 §37) prevents "Black" and "black" being added as two
  distinct values of the same attribute.
- **Product** — the core catalog entity (Module 06 §5). Money as integer minor units +
  currency (ADR-003, unchanged convention from every other monetary field in this
  codebase). `ProductType` enum is schema-ready for all 5 initial types; only Simple
  and Variable have real behavior in B3.
- **ProductVariant** — denormalizes `store_id` onto itself (rather than resolving
  through `product_id`) so it participates in `BelongsToTenant` and tenant-aware
  indexing directly, consistent with every other tenant-owned table.

## Server-Authoritative Pricing (Module 06 §24-25)

Prices set here are **catalog-admin-set** values (an authorized store user configuring
their own product), not customer-submitted checkout values — Module 06 §24's "client-
submitted prices must never be trusted" governs the future Cart/Checkout module (Phase
B7), which will recalculate from `Product::effectivePriceMinor()`/
`ProductVariant::effectivePriceMinor()`, never from anything a customer's request
carries. Cost price (`cost_price_minor`) is **never** included in `ProductResource`'s
JSON output unless `Gate::authorize('viewCostPrice', ...)` passes for the *current*
requester — built conditionally via `mergeWhen()`, not merely hidden by convention.

## Package Limit Enforcement (Module 06 §52-53) — First Real Use of B2's EntitlementService

`ProductController` is `EntitlementService::assertCanUse()`'s first real caller:

- **Create**: `assertCanUse('products.basic', 'max_products')` before the product is
  created; `recordUsage()` only after the transaction commits successfully (never
  increments on a failed create — Module 04's own "operation fails BUT usage
  permanently increments" anti-pattern, closed).
- **Status transitions**: archiving a product calls `releaseUsage()` (frees quota,
  per `ProductStatus::countsTowardUsageLimit()`'s documented decision); un-archiving
  re-checks the limit via `assertWithinLimit()` **before** allowing the transition —
  a status change can never become a silent backdoor around the same limit a create
  would have to pass.
- **Delete**: soft-deleting a product that was counting releases its usage.

**Known limitation, documented honestly**: unlike the usage *counter increment itself*
(atomic at the SQL level, per B2), the *create-then-record* sequence here has a small
window where two simultaneous requests could both pass `assertCanUse()`'s check before
either commits. This is a different situation from B2's pure-counter race: creating a
Product is a multi-step operation (validation, tenant-relation checks, insert), not a
single atomic statement, so closing this fully would require a row-level lock held for
the entire request. Given the consequence is minor commercial over-provisioning (not a
security or data-integrity issue), this is accepted as a documented trade-off rather
than adding write-lock complexity to every product creation — flagged for revisit if
real-world usage shows it matters in practice.

## Tenant Isolation Beyond the Model Layer

`exists:brands,id` / `exists:categories,id` Laravel validation rules only prove a row
exists *somewhere* — not in the caller's own tenant (these are global uniqueness/
existence checks with no tenant filter). `ProductController::assertRelationsBelongToTenant()`
re-checks `brand_id`, `primary_category_id`, and every `category_ids` entry via
`Brand::find()`/`Category::find()`, which **are** tenant-scoped by `BelongsToTenant`'s
global scope — a Store A request referencing Store B's brand/category ID gets a 422
validation error, never a silent cross-tenant association. This mirrors
`CategoryController::assertValidParent()`'s identical pattern for `parent_id`.

## Authorization

Every new Policy (`ProductPolicy`, `CategoryPolicy`, `BrandPolicy`, `AttributePolicy`)
extends `BaseTenantPolicy` and reuses its `isOwner()` helper — moved from being
duplicated per-Policy (as it was in B1's `RolePolicy`) into the shared base class this
milestone, closing a code-duplication issue before it multiplied across 4 new Policies.
`ProductVariant` has no separate Policy at all — variant access follows the parent
Product's Policy, since a variant has no independent authorization question (Module 06
§9: "an independently *sellable* configuration," not an independently *authorized* one).

## API Endpoints Added in B3

| Method | Path | Notes |
|---|---|---|
| GET/POST/PUT/DELETE | `/api/v1/products[/{product}]` | package-limit + cost-price gating |
| GET/POST/PUT/DELETE | `/api/v1/products/{product}/variants[/{variant}]` | authorized via parent Product |
| GET/POST/PUT/DELETE | `/api/v1/categories[/{category}]` | parenting-rule validated |
| GET/POST/PUT/DELETE | `/api/v1/brands[/{brand}]` | |
| GET/POST/DELETE | `/api/v1/attributes[/{attribute}]` | values created inline with the attribute |

## Deferred (see inspection findings for the full, explicit list)

Bulk import/export, search index/Meilisearch integration, cache invalidation for
public catalog reads, catalog events on the outbox, AI product foundation, catalog
analytics/readiness score, duplicate detection, catalog snapshots, store cloning,
sales channels, locale/RTL catalog, variant auto-generation from option combinations,
concurrent editing/autosave.
