<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase P4a: one product (or variant) of a stock receipt.
 *
 * @property int $id
 * @property int $stock_receipt_id
 * @property int $inventory_id
 * @property int $quantity
 */
final class StockReceiptLine extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'stock_receipt_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /** @return BelongsTo<Inventory, $this> */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }
}
