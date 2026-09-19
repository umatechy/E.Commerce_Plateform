# Phase B3 — Focused Security Review

Static/design-level review only — **NOT EXECUTED — DEFERRED TO VS CODE RUNTIME
VERIFICATION**.

| Item | Finding | Status |
|---|---|---|
| **Tenant isolation** | `Product`, `Category`, `Brand`, `Attribute`, `ProductVariant` all use `BelongsToTenant`. Cross-tenant read/update/delete via a guessed ID → 404 (asserted in `CatalogTenantIsolationTest`, 3 methods). | Reviewed — OK |
| **Cross-tenant relation assignment** | **Found and fixed this milestone**: `exists:brands,id`/`exists:categories,id` validation rules have no tenant filter — a Store A request could otherwise reference Store B's brand/category ID and pass Laravel's own validation. Closed via `ProductController::assertRelationsBelongToTenant()` (tenant-scoped `find()` re-check) and `CategoryController::assertValidParent()` (same pattern, already needed for the parenting-cycle check). Asserted in `test_store_a_cannot_assign_a_product_to_store_bs_category`/`...brand`. | **Found and fixed** |
| **IDOR** | Route-model binding + global scope make a cross-tenant Product/Category/Brand unreachable before any Policy or controller logic runs. | Reviewed — OK |
| **Authorization** | Every controller action calls `Gate::forUser(...)->authorize(...)` explicitly — no endpoint relies on an implicit or missing check (verified present in every method of every new controller by inspection). | Reviewed — OK |
| **Privilege escalation** | `isOwner()` (moved to `BaseTenantPolicy` this milestone, was duplicated per-Policy) is scoped to `$user->activeStoreId()` — cannot be satisfied by Owner status in a different store. | Reviewed — OK |
| **Cost price exposure** | `ProductResource`/`ProductVariantResource` build the `cost_price_minor` field conditionally via `Gate::forUser($request->user())->allows('viewCostPrice', ...)` — a request without that permission (and not Owner) never receives the field at all, not a null-masked one. Asserted in `test_cost_price_is_hidden_from_user_without_permission`. | Reviewed — OK |
| **Price manipulation** | Prices accepted here are catalog-admin-set (an authorized store user's own configuration), not customer-submitted checkout data — Module 06 §24's "never trust client-submitted prices" is a Cart/Checkout (Phase B7) concern; flagged so that milestone does not skip recalculating from these stored values. | N/A this milestone, flagged for Phase B7 |
| **Mass assignment** | Every model uses explicit `$fillable`; `store_id` is never accepted directly from a request — `BelongsToTenant`'s `creating` hook is the only place it is set. | Reviewed — OK |
| **Package-limit bypass** | `ProductController` is the only code path that calls `recordUsage()`/`releaseUsage()` for `max_products` — verified by inspection, no other controller/service touches this metric key. | Reviewed — OK |
| **Package-limit race condition** | Documented, not silently accepted — see `docs/architecture/b3-catalog.md` "Known limitation": the create-then-record sequence has a small window distinct from B2's atomic counter increment. Assessed as low-severity (commercial over-provisioning, not a security boundary) and accepted rather than adding request-level locking prematurely. | Reviewed — documented trade-off |
| **Circular category hierarchy** | Explicitly tested (`test_category_cannot_become_its_own_parent`, `test_category_cannot_be_moved_under_its_own_descendant`) — a cycle would otherwise make ancestor-walking code (`Category::ancestors()`) infinite-loop. | Reviewed — OK, fixed by design |
| **Soft-delete / historical integrity** | Products/Categories/Brands/Variants all soft-delete (Module 06 §32 "Historical Order Integrity" — deleting a product must not break old orders once Orders exist in Phase B5). Attributes hard-delete (no soft-delete column) — lower business-integrity risk, documented in `AttributeController::destroy()`'s comment. | Reviewed — OK |
| **Sensitive data exposure** | `CategoryResource`/`BrandResource`/`AttributeResource` expose only public catalog fields; no internal metadata leaked. | Reviewed — OK |
| **API manipulation** | No endpoint in B3 accepts a `store_id` parameter anywhere — every tenant-owned write resolves it exclusively from `TenantContext`. | Reviewed — OK |

## Issues Found and Fixed Within B3 Scope

1. **Cross-tenant brand/category assignment via `exists:` validation rules having no
   tenant filter** — the most significant finding this milestone. **Fixed.**
2. **`isOwner()` was duplicated in `RolePolicy` and would have been duplicated again
   in 4 new Catalog policies** — **fixed** by moving it into `BaseTenantPolicy` (shared
   base class), removing the duplicate from `RolePolicy` in the same change.

## Issues Documented for Later Modules (Outside B3 Scope)

1. Package-limit create-then-record race window (see table above) — accepted trade-off,
   revisit if real usage data shows it matters.
2. Client-submitted price trust boundary is Phase B7 (Cart/Checkout)'s responsibility to
   enforce when it recalculates from these stored catalog values — flagged, not built
   here (no checkout exists yet to enforce anything against).
3. Search index, cache invalidation, and outbox events for catalog changes are not
   wired — no consumer exists yet (storefront, Phase B10) that would need them.

None of the "found and fixed" items required deleting or resetting existing B0/B1/B2
work. No destructive database operation was performed (all 7 new migrations are
additive/new-table only).
