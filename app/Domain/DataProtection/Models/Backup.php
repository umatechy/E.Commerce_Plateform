<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Models;

use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deliberately does NOT use BelongsToTenant — a Backup legitimately
 * spans both Platform (store_id null) and Store scope, the same
 * reasoning B17's SettingRevision already established. Every query
 * MUST apply an explicit store_id filter for store-scoped access —
 * never an implicit/automatic one — see BackupService/BackupPolicy.
 * "Dumb" — BackupService is the only writer of `status`/`manifest`/etc.
 */
final class Backup extends Model
{
    use HasFactory, HasPublicId;

    protected $table = 'backups';

    /**
     * Mirrors the column defaults in the migration so a freshly created
     * model exposes them without a refresh() — resources read ->value on
     * these enum casts and threw on null (found on the first real run).
     */
    protected $attributes = [
        'status' => 'created',
        'retention_tier' => 'manual',
    ];

    protected $fillable = [
        'public_id', 'scope', 'store_id', 'status', 'initiated_by', 'initiated_by_user_id',
        'retention_tier', 'schedule_key',
        'storage_disk', 'storage_path', 'size_bytes', 'checksum_sha256', 'is_encrypted', 'compression',
        'manifest', 'failure_reason', 'started_at', 'completed_at', 'verified_at', 'last_checked_at',
        'expires_at', 'request_id',
    ];

    protected function casts(): array
    {
        return [
            'scope' => BackupScope::class,
            'status' => BackupStatus::class,
            'initiated_by' => BackupInitiator::class,
            'retention_tier' => BackupRetentionTier::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'is_encrypted' => 'boolean',
            'manifest' => 'array',
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<\App\Domain\Tenancy\Models\Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Tenancy\Models\Store::class);
    }

    /**
     * Routes take the public id (what the API returns as `id`). The
     * numeric id still resolves, for callers written before Phase B30.
     * Finding the row is not authorization: every controller checks the
     * backup's store against the resolved tenant itself.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        $value = (string) $value;

        return self::query()->where(\Illuminate\Support\Str::isUlid($value) ? 'public_id' : 'id', $value)->first();
    }

    public function isRestoreEligible(): bool
    {
        return $this->status === BackupStatus::Verified && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
