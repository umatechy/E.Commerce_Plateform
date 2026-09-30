<?php

declare(strict_types=1);

namespace App\Domain\Cart\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CartItem extends Model
{
    use BelongsToTenant;

    protected $table = 'cart_items';

    protected $fillable = ['store_id', 'cart_id', 'product_id', 'product_variant_id', 'quantity', 'price_at_add_minor'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'price_at_add_minor' => 'integer'];
    }

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
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
