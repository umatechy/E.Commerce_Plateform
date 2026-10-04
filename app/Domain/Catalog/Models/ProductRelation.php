<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase B39 — Module 06 §38: one product shown with another — related,
 * cross-sell, up-sell or alternative. Both products are of the same store.
 *
 * @property int $id
 * @property int $product_id
 * @property int $related_product_id
 * @property string $type
 * @property int $position
 */
final class ProductRelation extends Model
{
    use BelongsToTenant;

    public const TYPES = ['related', 'cross_sell', 'up_sell', 'alternative'];

    public const UPDATED_AT = null;

    protected $fillable = ['product_id', 'related_product_id', 'type', 'position'];

    /** @return BelongsTo<Product, $this> */
    public function related(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'related_product_id');
    }
}
