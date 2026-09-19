<?php

declare(strict_types=1);

namespace App\Domain\Orders\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only — never updated/deleted by application code (same convention as B4's StockMovement). */
final class OrderTimelineEvent extends Model
{
    use BelongsToTenant;

    protected $table = 'order_timeline_events';

    public $timestamps = false; // created_at only

    protected $fillable = [
        'store_id', 'order_id', 'event_type', 'from_status', 'to_status',
        'actor_id', 'reason', 'note',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
