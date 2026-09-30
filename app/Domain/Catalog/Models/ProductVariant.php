<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ProductVariant extends Model
{
    use BelongsToTenant, HasFactory, HasPublicId, SoftDeletes;

    protected $table = 'product_variants';

    protected $fillable = [
        'store_id', 'product_id', 'sku', 'barcode',
        'price_minor', 'sale_price_minor', 'cost_price_minor',
        'weight', 'status', 'option_values',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:3',
            'option_values' => 'array',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function effectivePriceMinor(): ?int
    {
        if ($this->sale_price_minor !== null && $this->price_minor !== null
            && $this->sale_price_minor < $this->price_minor) {
            return $this->sale_price_minor;
        }

        return $this->price_minor;
    }
}
