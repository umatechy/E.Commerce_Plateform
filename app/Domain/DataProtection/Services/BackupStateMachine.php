<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Services;

use App\Domain\DataProtection\Exceptions\InvalidBackupStateTransitionException;
use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupStatus;

/** Module 23 §17 "Backup States" — explicit, validated transitions only (this milestone's own Phase 11 instruction). */
final class BackupStateMachine
{
    private const ALLOWED = [
        'created' => ['queued', 'cancelled'],
        'queued' => ['running', 'cancelled'],
        'running' => ['verifying', 'failed'],
        'verifying' => ['verified', 'failed'],
        // 'failed': a later integrity re-check found the stored artifact missing or changed (Phase B30).
        'verified' => ['expired', 'deleted', 'restoring', 'failed'],
        'restoring' => ['restored', 'restore_failed'],
        'restored' => ['verified'],
        'restore_failed' => ['verified'],
        'failed' => ['deleted'],
        'expired' => ['deleted'],
        'cancelled' => ['deleted'],
    ];

    /**
     * @throws InvalidBackupStateTransitionException
     */
    public function transition(Backup $backup, BackupStatus $to): void
    {
        $from = $backup->status;
        $allowed = self::ALLOWED[$from->value] ?? [];

        if (! in_array($to->value, $allowed, true)) {
            throw new InvalidBackupStateTransitionException($from->value, $to->value);
        }

        $backup->update(['status' => $to]);
    }
}
