<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Dumb" — RestoreService is the only writer of `status`. */
final class BackupRestoreJob extends Model
{
    protected $table = 'backup_restore_jobs';

    protected $fillable = [
        'backup_id', 'target_store_id', 'pre_restore_backup_id', 'status',
        'requested_by_user_id', 'authorized_by_user_id', 'failure_reason', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['status' => RestoreStatus::class, 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    public function preRestoreBackup(): BelongsTo
    {
        return $this->belongsTo(Backup::class, 'pre_restore_backup_id');
    }
}
