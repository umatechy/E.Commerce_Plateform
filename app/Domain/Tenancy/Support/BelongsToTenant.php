<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent trait implementing ADR-001 Layer 3 (mandatory global scope).
 *
 * Apply this trait to every tenant-owned model. It:
 *  1. Adds a global scope that filters every query by the resolved
 *     TenantContext's store_id — application code never needs to
 *     remember to add ->where('store_id', ...) manually.
 *  2. Auto-fills store_id on creation from the resolved TenantContext.
 *  3. Refuses to create a row while no tenant context is resolved
 *     (fails loudly rather than creating an orphaned/global row).
 *
 * Platform-only models (no store_id column at all) must NOT use this
 * trait — its absence is itself meaningful, per ADR-001 §8.
 *
 * Layer 4 (relationship-level / route-model-binding ownership re-check)
 * is implemented separately per-controller via
 * App\Http\Middleware\Concerns\ResolvesOwnedRouteModel, so that even a
 * guessed numeric ID for another tenant's row resolves to "not found"
 * at the routing layer, not just at the query layer.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            /** @var TenantContext $context */
            $context = app(TenantContext::class);

            if ($context->isPlatform()) {
                // Explicit, audited platform/Super-Admin context (ADR-001
                // Layer 7/9) — intentionally unscoped. The caller (Super
                // Admin controller/service) is responsible for its own
                // permission check and audit log entry; this trait only
                // recognises the already-resolved, already-authorized
                // platform context — it does not grant it.
                return;
            }

            $builder->where(
                $builder->getModel()->getTable().'.store_id',
                $context->storeId()
            );
        });

        static::creating(function (Model $model) {
            /** @var TenantContext $context */
            $context = app(TenantContext::class);

            if (! $context->isPlatform() && empty($model->store_id)) {
                $model->store_id = $context->storeId();
            }
        });
    }

    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        // Escape hatch reserved for reviewed, audited platform-level code
        // paths only (e.g. Super Admin reporting). Every call site using
        // this scope must be flagged in code review per ADR-001 Layer 5.
        return $query->withoutGlobalScope('tenant');
    }

    /**
     * CRITICAL FIX (found during Phase B4 — see
     * docs/development/b4-inspection-findings.md "Critical Cross-Cutting
     * Fix"): every tenant-owned model needs a `store()` relationship for
     * two reasons that had NOTHING to do with each other until this bug
     * connected them:
     *   1. It is the natural, conventional Eloquent relationship a
     *      tenant-owned row should expose to its owning Store.
     *   2. Laravel's `Model::factory()->for($store)` factory state
     *      REQUIRES this exact relationship to exist (it reflects on it
     *      to determine the foreign key) — and it was MISSING on every
     *      tenant-owned model (Role, Category, Brand, Product,
     *      ProductVariant, Subscription, Warehouse, Inventory, ...)
     *      since Phase B1. Every test in the suite that wrote
     *      `Xyz::factory()->for($store)->create()` — the overwhelming
     *      majority of this codebase's Feature tests — would have
     *      thrown `BadMethodCallException: Call to undefined method
     *      ...::store()` the first time it actually ran against a real
     *      PHP runtime. This was never caught earlier because nothing
     *      in this Claude App environment has executed PHP at any
     *      point (see every checkpoint's "Runtime Verification"
     *      section).
     *
     * Defining it ONCE here, in the shared trait, fixes every
     * tenant-owned model retroactively in a single change, rather than
     * adding an identical method to a dozen individual model classes.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Tenancy\Models\Store::class);
    }
}
