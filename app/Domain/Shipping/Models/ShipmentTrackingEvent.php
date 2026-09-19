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

    public $timestamps = false; // created_at only, see migration

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
