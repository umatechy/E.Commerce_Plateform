<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Module 07 §5-12. Self-referencing hierarchy (parent_id). Parenting
 * rules (§8: no self-parent, no cycles, same-tenant-only) are validated
 * in CategoryController/StoreCategoryRequest BEFORE save — this model
 * itself has no validation logic (kept in the request/controller layer,
 * consistent with every other resource in this codebase).
 */
final class Category extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId, SoftDeletes;

    protected $table = 'categories';

    /**
     * Mirrors the column defaults in the migration so a freshly created
     * model exposes them without a refresh() — resources read ->value on
     * these enum casts and threw on null (found on the first real run).
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    protected $fillable = [
        'store_id', 'parent_id', 'name', 'slug', 'description',
        'status', 'visibility', 'sort_order', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CategoryStatus::class,
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_category');
    }

    /** Module 07 §7 "Retrieve Ancestors" — walked in PHP; category trees are shallow enough that N+1 here is acceptable for B3's admin UI (not a public/high-traffic path). */
    public function ancestors(): array
    {
        $ancestors = [];
        $current = $this->parent;

        while ($current !== null) {
            $ancestors[] = $current;
            $current = $current->parent;
        }

        return array_reverse($ancestors);
    }
}
