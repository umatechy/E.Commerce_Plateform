<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Models;

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
    protected $table = 'backups';

    protected $fillable = [
        'public_id', 'scope', 'store_id', 'status', 'initiated_by', 'initiated_by_user_id',
        'storage_disk', 'storage_path', 'size_bytes', 'checksum_sha256', 'is_encrypted',
        'manifest', 'failure_reason', 'verified_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'scope' => BackupScope::class,
            'status' => BackupStatus::class,
            'initiated_by' => BackupInitiator::class,
            'is_encrypted' => 'boolean',
            'manifest' => 'array',
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Tenancy\Models\Store::class);
    }

    public function isRestoreEligible(): bool
    {
        return $this->status === BackupStatus::Verified && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
