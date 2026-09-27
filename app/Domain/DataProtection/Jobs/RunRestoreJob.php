<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Jobs;

use App\Domain\DataProtection\Models\BackupRestoreJob;
use App\Domain\DataProtection\Services\DumpStrategies\DatabaseRestoreStrategy;
use App\Domain\DataProtection\Services\Storage\BackupStorageAdapter;
use App\Domain\Events\Support\RecordsOutboxEvents;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Module 23 Phase 16/21 "Restore Architecture / Restore Failure
 * Handling" — Non-Negotiable: "never mark a restore successful merely
 * because the restore job started." Only tries=1 — a restore is NOT
 * safely retryable the way a backup is (re-running an import against a
 * database that partially received the previous attempt's data is
 * itself a data-integrity risk); a failed restore requires a human
 * decision (roll forward using the pre-restore safety backup), not an
 * automatic retry.
 */
final class RunRestoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $restoreJobId) {}

    public function handle(DatabaseRestoreStrategy $restorer, BackupStorageAdapter $storage, RecordsOutboxEvents $outbox): void
    {
        $restoreJob = BackupRestoreJob::query()->with('backup')->findOrFail($this->restoreJobId);

        if ($restoreJob->status->value !== 'running') {
            return; // not in the expected state — never blindly re-execute
        }

        try {
            $localPath = $storage->retrieveToLocalPath($restoreJob->backup->storage_path);
            $restorer->restore($localPath);
            @unlink($localPath); // the local copy of a full database dump must never linger on disk after use

            $restoreJob->update(['status' => 'completed', 'completed_at' => now()]);

            Log::channel('audit')->info('restore.completed', ['restore_job_id' => $restoreJob->id, 'backup_id' => $restoreJob->backup_id]);

            $outbox->recordEvent(
                eventType: 'restore.completed',
                payload: ['restore_job_id' => $restoreJob->id, 'backup_id' => $restoreJob->backup_id],
                idempotencyKey: "restore_job:{$restoreJob->id}:completed",
            );
        } catch (\Throwable $e) {
            $restoreJob->update(['status' => 'failed', 'failure_reason' => $e->getMessage()]);

            Log::channel('audit')->info('restore.failed', ['restore_job_id' => $restoreJob->id, 'reason' => $e->getMessage()]);

            $outbox->recordEvent(
                eventType: 'restore.failed',
                payload: ['restore_job_id' => $restoreJob->id, 'backup_id' => $restoreJob->backup_id],
                idempotencyKey: "restore_job:{$restoreJob->id}:failed",
            );

            // Deliberately NOT re-thrown — Module 23 Phase 21 "never
            // silently leave the system in a falsely healthy state" is
            // satisfied by the explicit 'failed' status above; re-
            // throwing would only trigger Laravel's own failed-job
            // handling on top of the state this service already
            // recorded correctly, and tries=1 means no retry would
            // happen anyway.
        }
    }
}
