<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only ledger row (Module 08 §30). Never updated or deleted by
 * application code — see InventoryService and the migration's docblock.
 */
final class StockMovement extends Model
{
    use BelongsToTenant;

    protected $table = 'stock_movements';

    public $timestamps = false; // created_at only, see migration

    protected $fillable = [
        'store_id', 'inventory_id', 'type', 'quantity',
        'previous_on_hand', 'new_on_hand',
        'reference_type', 'reference_id', 'actor_id',
        'reason', 'notes', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => 'integer',
            'previous_on_hand' => 'integer',
            'new_on_hand' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }
}
