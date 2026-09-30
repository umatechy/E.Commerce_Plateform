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

    /**
     * Mirrors the column defaults in the migration so a freshly created
     * model exposes them without a refresh() — resources read ->value on
     * these enum casts and threw on null (found on the first real run).
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    // created_at only (see migration). UPDATED_AT = null keeps Eloquent
    // filling created_at itself, so a just-created row exposes it
    // without a refresh (resources call ->toIso8601String() on it).
    public const UPDATED_AT = null;

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
