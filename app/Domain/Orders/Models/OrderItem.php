<?php

declare(strict_types=1);

namespace App\Domain\Orders\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 09 §10-11 — snapshot fields are authoritative forever;
 * product()/variant() are internal references only, never re-read for
 * historical price/name display (Module 09 Final Rule #24: "historical
 * order integrity must survive product/customer changes").
 */
final class OrderItem extends Model
{
    use BelongsToTenant;

    protected $table = 'order_items';

    protected $fillable = [
        'store_id', 'order_id', 'product_id', 'product_variant_id',
        'product_name_snapshot', 'sku_snapshot', 'variant_snapshot',
        'quantity', 'unit_price_minor', 'discount_minor', 'tax_minor', 'line_total_minor',
        'fulfillment_status',
    ];

    protected function casts(): array
    {
        return [
            'variant_snapshot' => 'array',
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'line_total_minor' => 'integer',
            'fulfillment_status' => FulfillmentStatus::class,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
