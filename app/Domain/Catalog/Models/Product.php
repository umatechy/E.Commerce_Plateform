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
 * Module 06 §5. Money fields are integer minor units + currency
 * (ADR-003) — never float. cost_price_minor is permission-gated at the
 * Resource layer (ProductResource), never trusted from or exposed to
 * an unauthorized caller (Module 06 §25).
 */
final class Product extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId, SoftDeletes;

    protected $table = 'products';

    /**
     * Mirrors the column defaults in the migration so a freshly created
     * model exposes them without a refresh() — resources read ->value on
     * these enum casts and threw on null (found on the first real run).
     */
    protected $attributes = [
        'type' => 'simple',
        'status' => 'draft',
        'visibility' => 'hidden',
    ];

    protected $fillable = [
        'store_id', 'type', 'name', 'slug', 'sku', 'short_description', 'description',
        'status', 'visibility', 'brand_id', 'primary_category_id',
        'price_minor', 'sale_price_minor', 'cost_price_minor', 'currency',
        'published_at', 'archived_at',
        'is_featured', // Phase B39 (Module 06 §37)
        'sort_priority', // Phase B43 (Module 06 §93)
    ];

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'status' => ProductStatus::class,
            'visibility' => ProductVisibility::class,
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
            'is_featured' => 'boolean',
            'sort_priority' => 'integer',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function primaryCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'primary_category_id');
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_category');
    }

    /**
     * Phase B39 (Module 06 §34): the collections that list this product by hand
     * (rule-based collections match it through StorefrontCatalog::applyCollection).
     *
     * @return BelongsToMany<Collection, $this>
     */
    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class)->withPivot('position');
    }

    /** @return BelongsToMany<Badge, $this> Phase B43 (Module 06 §36): the store's own badges on this product */
    public function badges(): BelongsToMany
    {
        return $this->belongsToMany(Badge::class, 'product_badge');
    }

    /** @return BelongsToMany<Tag, $this> Phase B39 (Module 06 §35) */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('name');
    }

    /** @return HasMany<ProductRelation, $this> Phase B39 (Module 06 §38) */
    /** @return HasMany<ProductAttributeValue, $this> Phase B41: specifications (written by ProductSpecifications) */
    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class)->orderBy('id');
    }

    // Not relations(): Eloquent keeps a model's loaded relations in $relations.
    public function productRelations(): HasMany
    {
        return $this->hasMany(ProductRelation::class)->orderBy('position');
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** @return HasMany<ProductImage, $this> Phase B24: storefront images, in display order. */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position')->orderBy('id');
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
