<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Console;

use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\DataProtection\Services\BackupStateMachine;
use App\Domain\DataProtection\Services\Storage\BackupStorageAdapter;
use Illuminate\Console\Command;

/**
 * Module 23 Phase 14 "Retention" — reuses the EXISTING Laravel
 * scheduler (routes/console.php), never a custom one. Non-Negotiable:
 * never deletes a backup currently being restored, required by an
 * active BackupRestoreJob, or protected by an explicit lifecycle rule
 * — the WHERE clause below structurally excludes 'restoring' and
 * 'restored' status backups, and a Verified backup referenced as
 * ANY restore job's pre_restore_backup_id is excluded via a NOT EXISTS
 * subquery, regardless of its own expiry.
 *
 * NOT EXECUTED — ENVIRONMENT LIMITATION: this command has never been
 * run against a real database in this Claude App sandbox.
 */
final class ExpireOldBackupsCommand extends Command
{
    protected $signature = 'backups:expire';

    protected $description = 'Expire and delete backups past their retention period, protecting active/restore-referenced backups.';

    public function handle(BackupStateMachine $stateMachine, BackupStorageAdapter $storage): int
    {
        $expired = Backup::query()
            ->whereIn('status', [BackupStatus::Verified->value, BackupStatus::Failed->value])
            ->where('expires_at', '<', now())
            ->whereNotIn('id', function ($query) {
                $query->select('pre_restore_backup_id')
                    ->from('backup_restore_jobs')
                    ->whereNotNull('pre_restore_backup_id');
            })
            ->get();

        foreach ($expired as $backup) {
            if ($backup->status === BackupStatus::Verified) {
                $stateMachine->transition($backup, BackupStatus::Expired);
            }

            if ($backup->storage_path !== null && $storage->exists($backup->storage_path)) {
                $storage->delete($backup->storage_path);
            }

            $stateMachine->transition($backup->fresh(), BackupStatus::Deleted);

            $this->info("Expired and deleted backup {$backup->public_id}.");
        }

        $this->info("Processed {$expired->count()} expired backup(s).");

        return self::SUCCESS;
    }
}
