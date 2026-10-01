<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Console;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupRestoreJob;
use App\Domain\DataProtection\Models\BackupScope;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\DataProtection\Models\RestoreMode;
use App\Domain\DataProtection\Models\RestoreStatus;
use App\Domain\DataProtection\Services\RestoreRehearsalService;
use App\Domain\DataProtection\Services\Storage\BackupStorageAdapter;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Support\RequestId;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Module 23 Phase 14 "Retention" (§29–30) — reuses the EXISTING Laravel
 * scheduler (routes/console.php), never a custom one.
 *
 * What is never deleted, whatever its expiry date:
 * - a backup that a restore or a rehearsal is using or waiting to use;
 * - a pre-restore safety backup that a restore job refers to;
 * - the newest verified platform backup: the last recovery point.
 *
 * Nothing is deleted at all while a production restore or a rehearsal
 * is running.
 *
 * It is safe to run twice, or from two servers at once: each step is a
 * conditional update that only one runner wins. If one backup cannot be
 * removed, the others still are, the failure is audited and a critical
 * alert is raised; the next run tries that backup again.
 */
final class ExpireOldBackupsCommand extends Command
{
    private const REMOVABLE = ['verified', 'failed', 'expired', 'cancelled'];

    protected $signature = 'backups:expire';

    protected $description = 'Delete backups past their retention period, keeping every backup a recovery still needs.';

    public function handle(BackupStorageAdapter $storage, AuditLogger $audit, RecordsOutboxEvents $outbox): int
    {
        RequestId::begin('backup-retention');

        $lock = Cache::lock('backups:retention', 900);

        if (! $lock->get()) {
            $this->info('Retention cleanup is already running elsewhere.');

            return self::SUCCESS;
        }

        try {
            if ($this->restoreOrRehearsalRunning()) {
                $this->info('A restore or rehearsal is in progress. Nothing was deleted; the next run will.');

                return self::SUCCESS;
            }

            $failures = [];
            $deleted = 0;

            foreach ($this->expired() as $backup) {
                try {
                    $deleted += $this->remove($backup, $storage, $audit) ? 1 : 0;
                } catch (\Throwable $e) {
                    $failures[$backup->public_id] = Str::limit($e->getMessage(), 200, '…');
                    $this->error("Could not delete backup {$backup->public_id}: {$failures[$backup->public_id]}");
                }
            }

            $this->info("Deleted {$deleted} expired backup(s).");

            if ($failures === []) {
                return self::SUCCESS;
            }

            $audit->record('backup.retention_cleanup_failed', ['failed' => count($failures), 'deleted' => $deleted, 'backups' => $failures], platform: true);

            // One alert per day, however often the cleanup runs.
            DB::transaction(fn () => $outbox->recordEventOnceFor(
                null,
                eventType: 'backup.retention_cleanup_failed',
                payload: ['failed' => count($failures), 'deleted' => $deleted, 'backup_public_ids' => array_keys($failures), 'reason' => (string) reset($failures), 'request_id' => RequestId::current()],
                idempotencyKey: 'backup:retention_cleanup_failed:'.now()->toDateString(),
            ));

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    /** @return Collection<int, Backup> */
    private function expired(): Collection
    {
        $lastRecoveryPoint = Backup::query()
            ->where('scope', BackupScope::Platform->value)
            ->where('status', BackupStatus::Verified->value)
            ->orderByDesc('verified_at')
            ->value('id');

        return Backup::query()
            ->whereIn('status', self::REMOVABLE)
            ->where('expires_at', '<', now())
            ->when($lastRecoveryPoint !== null, fn ($q) => $q->where('id', '!=', $lastRecoveryPoint))
            // Safety snapshots a restore job points at.
            ->whereNotIn('id', fn ($q) => $q->select('pre_restore_backup_id')->from('backup_restore_jobs')->whereNotNull('pre_restore_backup_id'))
            // Backups a restore or rehearsal is using or still waiting to use.
            ->whereNotIn('id', fn ($q) => $q->select('backup_id')->from('backup_restore_jobs')->whereIn('status', [RestoreStatus::Requested->value, RestoreStatus::Running->value]))
            ->orderBy('id')
            ->get();
    }

    /** @return bool whether this run removed it (false: another runner got there first) */
    private function remove(Backup $backup, BackupStorageAdapter $storage, AuditLogger $audit): bool
    {
        if ($backup->status === BackupStatus::Verified) {
            // From here the backup can no longer be chosen for a restore.
            $claimed = Backup::query()->whereKey($backup->id)->where('status', BackupStatus::Verified->value)->update(['status' => BackupStatus::Expired->value]);

            if ($claimed === 1) {
                $audit->record('backup.expired', ['backup_id' => $backup->public_id, 'retention_tier' => $backup->retention_tier->value], $backup, $backup->store_id);
            }
        }

        // The artifact first: a backup is only "deleted" once its file is gone.
        if ($backup->storage_path !== null && $storage->exists($backup->storage_path)) {
            $storage->delete($backup->storage_path);
        }

        $marked = Backup::query()->whereKey($backup->id)->whereIn('status', ['expired', 'failed', 'cancelled'])->update(['status' => BackupStatus::Deleted->value]);

        if ($marked !== 1) {
            return false;
        }

        $audit->record('backup.deleted', ['backup_id' => $backup->public_id, 'reason' => 'retention'], $backup, $backup->store_id);
        $this->line("Deleted backup {$backup->public_id}.");

        return true;
    }

    private function restoreOrRehearsalRunning(): bool
    {
        if (BackupRestoreJob::query()->where('mode', RestoreMode::Production->value)->where('status', RestoreStatus::Running->value)->exists()) {
            return true;
        }

        $probe = Cache::lock(RestoreRehearsalService::LOCK, 5);

        if (! $probe->get()) {
            return true;
        }

        $probe->release();

        return false;
    }
}
