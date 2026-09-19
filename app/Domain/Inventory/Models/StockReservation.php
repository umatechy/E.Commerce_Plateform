<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockReservation extends Model
{
    use BelongsToTenant;

    protected $table = 'stock_reservations';

    protected $fillable = [
        'store_id', 'inventory_id', 'quantity', 'status',
        'reference_type', 'reference_id', 'idempotency_key',
        'expires_at', 'released_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'quantity' => 'integer',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }
}
