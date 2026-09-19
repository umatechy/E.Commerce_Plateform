<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Module 06 §5. Money fields are integer minor units + currency
 * (ADR-003) — never float. cost_price_minor is permission-gated at the
 * Resource layer (ProductResource), never trusted from or exposed to
 * an unauthorized caller (Module 06 §25).
 */
final class Product extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $table = 'products';

    protected $fillable = [
        'store_id', 'type', 'name', 'slug', 'sku', 'short_description', 'description',
        'status', 'visibility', 'brand_id', 'primary_category_id',
        'price_minor', 'sale_price_minor', 'cost_price_minor', 'currency',
        'published_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'status' => ProductStatus::class,
            'visibility' => ProductVisibility::class,
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function primaryCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'primary_category_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_category');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** Module 06 §28: "Only appropriate active products should appear publicly." */
    public function isPubliclyVisible(): bool
    {
        return $this->status->isPubliclyVisible() && $this->visibility === ProductVisibility::Public;
    }

    /**
     * Module 06 §23 "Sale Price" — effective price respects an active
     * sale price when present and lower than the regular price; never
     * trusted from any client input (this is catalog-admin-set, server-
     * stored data, not a customer-submitted value — Module 06 §24's
     * "client-submitted prices must never be trusted" governs checkout,
     * which recalculates from THIS stored value, not the reverse).
     */
    public function effectivePriceMinor(): ?int
    {
        if ($this->sale_price_minor !== null && $this->price_minor !== null
            && $this->sale_price_minor < $this->price_minor) {
            return $this->sale_price_minor;
        }

        return $this->price_minor;
    }
}
