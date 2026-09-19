<?php

declare(strict_types=1);

namespace App\Domain\Events\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-004 transactional outbox row. Written inside the SAME database
 * transaction as the business state change it describes — this is what
 * guarantees "database state and critical integration events cannot
 * silently diverge" (this prompt's Phase B0 Event/Outbox requirement).
 *
 * A dedicated dispatcher (App\Console\Commands\PublishOutboxEvents) reads
 * 'pending' rows and pushes them onto real Redis-backed queues as actual
 * jobs — this table is the durable record; the queue dispatch is the
 * async delivery mechanism built on top of it.
 */
final class OutboxEvent extends Model
{
    use BelongsToTenant;

    protected $table = 'outbox_events';

    public $timestamps = false; // created_at only; see migration.

    protected $fillable = [
        'store_id',
        'event_type',
        'payload',
        'idempotency_key',
        'status',
        'attempts',
        'available_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => OutboxEventStatus::class,
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
