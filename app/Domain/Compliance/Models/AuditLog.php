<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Models;

use App\Domain\Tenancy\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One immutable audit entry (Module 32). Written only by AuditLogger;
 * removed only by AuditRetentionService's bulk prune. Deliberately NOT
 * BelongsToTenant — one table holds every store's chain plus the platform
 * chain, so every read filters store_id explicitly (the Backup /
 * SettingRevision precedent).
 *
 * `context` is canonical JSON text (never re-encoded) because its exact
 * bytes are part of the row hash.
 */
final class AuditLog extends Model
{
    protected $table = 'audit_logs';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit log entries are removed only by retention pruning.'));
    }

    protected function casts(): array
    {
        return [
            'actor_type' => AuditActorType::class,
            'surface' => AuditSurface::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return array<string, mixed> */
    public function contextData(): array
    {
        return json_decode((string) $this->getRawOriginal('context'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
