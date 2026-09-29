<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only — never updated/deleted by application code (Module 13 §52). */
final class ShipmentTrackingEvent extends Model
{
    use BelongsToTenant;

    protected $table = 'shipment_tracking_events';

    // created_at only (see migration). UPDATED_AT = null keeps Eloquent
    // filling created_at itself, so a just-created row exposes it
    // without a refresh (resources call ->toIso8601String() on it).
    public const UPDATED_AT = null;

    protected $fillable = [
        'store_id', 'shipment_id', 'status', 'carrier_event_code',
        'description', 'location', 'source', 'actor_id', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['status' => ShipmentStatus::class, 'occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
