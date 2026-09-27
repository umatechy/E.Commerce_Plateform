<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Services;

use App\Domain\DataProtection\Exceptions\BackupNotRestoreEligibleException;
use App\Domain\DataProtection\Jobs\RunRestoreJob;
use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupInitiator;
use App\Domain\DataProtection\Models\BackupRestoreJob;
use App\Domain\DataProtection\Models\BackupScope;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\DataProtection\Services\DumpStrategies\DatabaseDumpStrategy;
use App\Domain\DataProtection\Services\Storage\BackupStorageAdapter;
use App\Domain\Events\Support\RecordsOutboxEvents;
use Illuminate\Support\Facades\Log;

/**
 * Module 23 Phase 16-19 "Restore Architecture / Authorization / Preflight
 * / Pre-Restore Safety" — Non-Negotiable: restore is HIGH RISK, never
 * a "download and overwrite" shortcut. See
 * docs/development/b19-inspection-findings.md "Architectural Decision
 * — Restore Is Platform-Level Only" — a Store Admin can REQUEST a
 * restore (this creates an auditable, preflight-checked record and
 * notifies Super Admin); only Super Admin can authorize + execute one,
 * since execution replaces the entire shared database.
 */
final class RestoreService
{
    public function __construct(private readonly RecordsOutboxEvents $outbox) {}

    /**
     * Module 23 Phase 18 "Restore Preflight" — runs BEFORE any
     * BackupRestoreJob row transitions past 'requested'. A failed
     * preflight is recorded (never silently dropped) but never queues
     * an actual restore.
     */
    public function requestRestore(Backup $backup, ?int $targetStoreId, int $requestedByUserId): BackupRestoreJob
    {
        $restoreJob = BackupRestoreJob::query()->create([
            'backup_id' => $backup->id,
            'target_store_id' => $targetStoreId,
            'requested_by_user_id' => $requestedByUserId,
            'status' => 'requested',
        ]);

        try {
            $this->preflight($backup);
        } catch (BackupNotRestoreEligibleException $e) {
            $restoreJob->update(['status' => 'preflight_failed', 'failure_reason' => $e->getMessage()]);

            Log::channel('audit')->info('restore.preflight_failed', ['restore_job_id' => $restoreJob->id, 'backup_id' => $backup->id, 'reason' => $e->getMessage()]);

            return $restoreJob->fresh();
        }

        $this->outbox->recordEvent(
            eventType: 'restore.requested',
            payload: ['restore_job_id' => $restoreJob->id, 'backup_id' => $backup->id, 'target_store_id' => $targetStoreId],
            idempotencyKey: "restore_job:{$restoreJob->id}:requested",
        );

        return $restoreJob;
    }

    /**
     * @throws BackupNotRestoreEligibleException
     */
    public function preflight(Backup $backup): void
    {
        if (! $backup->isRestoreEligible()) {
            $reason = match (true) {
                $backup->status !== BackupStatus::Verified => "backup status is \"{$backup->status->value}\", not \"verified\"",
                $backup->expires_at?->isPast() => 'backup has expired',
                default => 'backup is not eligible for restore',
            };

            throw new BackupNotRestoreEligibleException($reason);
        }
    }

    /**
     * Super-Admin-only action (enforced by the calling policy/controller,
     * never re-checked here — this service trusts its caller exactly
     * like every other domain service in this codebase). Takes an
     * immediate, synchronous pre-restore safety backup (Module 23 Phase
     * 19) BEFORE the destructive restore is even queued — if the safety
     * backup itself fails, the restore never proceeds.
     */
    public function authorizeAndExecute(
        BackupRestoreJob $restoreJob,
        int $authorizedByUserId,
        BackupService $backups,
        DatabaseDumpStrategy $dumper,
        BackupStorageAdapter $storage,
    ): void {
        if ($restoreJob->status->value !== 'requested') {
            throw new BackupNotRestoreEligibleException("restore job status is \"{$restoreJob->status->value}\", not \"requested\"");
        }

        $this->preflight($restoreJob->backup);

        $preRestoreBackup = $backups->requestBackup(BackupScope::Platform, null, BackupInitiator::PreRestoreSafety, $authorizedByUserId);
        $backups->execute($preRestoreBackup->fresh(), $dumper, $storage);

        $restoreJob->update([
            'pre_restore_backup_id' => $preRestoreBackup->id,
            'authorized_by_user_id' => $authorizedByUserId,
            'status' => 'running',
            'started_at' => now(),
        ]);

        Log::channel('audit')->info('restore.authorized', ['restore_job_id' => $restoreJob->id, 'authorized_by_user_id' => $authorizedByUserId, 'pre_restore_backup_id' => $preRestoreBackup->id]);

        RunRestoreJob::dispatch($restoreJob->id);
    }
}
