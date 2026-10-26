<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase P4a: one product (or variant) of a transfer, from its record at the
 * source to its record at the destination.
 *
 * @property int $id
 * @property int $stock_transfer_id
 * @property int $from_inventory_id
 * @property int $to_inventory_id
 * @property int $quantity
 */
final class StockTransferLine extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'stock_transfer_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /** @return BelongsTo<Inventory, $this> */
    public function fromInventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'from_inventory_id');
    }

    /** @return BelongsTo<Inventory, $this> */
    public function toInventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'to_inventory_id');
    }
}
