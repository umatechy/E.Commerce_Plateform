<?php

declare(strict_types=1);

namespace App\Domain\Cart\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WishlistItem extends Model
{
    use BelongsToTenant;

    // created_at only (see migration). UPDATED_AT = null keeps Eloquent
    // filling created_at itself, so a just-created row exposes it
    // without a refresh (resources call ->toIso8601String() on it).
    public const UPDATED_AT = null;

    protected $table = 'wishlist_items';

    protected $fillable = ['store_id', 'customer_id', 'product_id', 'product_variant_id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
