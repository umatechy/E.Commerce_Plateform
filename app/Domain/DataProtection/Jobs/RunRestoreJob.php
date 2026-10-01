<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Jobs;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\DataProtection\Models\BackupRestoreJob;
use App\Domain\DataProtection\Models\RestoreMode;
use App\Domain\DataProtection\Models\RestoreStatus;
use App\Domain\DataProtection\Services\BackupArtifactCodec;
use App\Domain\DataProtection\Services\DumpStrategies\DatabaseRestoreStrategy;
use App\Domain\DataProtection\Services\RestoreService;
use App\Domain\DataProtection\Services\Storage\BackupStorageAdapter;
use App\Domain\Events\Support\RecordsOutboxEvents;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Module 23 Phase 16/21 "Restore Architecture / Restore Failure
 * Handling" — Non-Negotiable: "never mark a restore successful merely
 * because the restore job started." Only tries=1 — a restore is NOT
 * safely retryable the way a backup is (re-running an import against a
 * database that partially received the previous attempt's data is
 * itself a data-integrity risk); a failed restore requires a human
 * decision (roll forward using the pre-restore safety backup), not an
 * automatic retry. There is no automatic rollback.
 *
 * The payload carries only the restore job's id (and the owner token of
 * the restore lock). What to restore is re-read from the row.
 */
final class RunRestoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $restoreJobId, public readonly ?string $lockOwner = null) {}

    public function handle(DatabaseRestoreStrategy $restorer, BackupStorageAdapter $storage, BackupArtifactCodec $codec, RecordsOutboxEvents $outbox, AuditLogger $audit): void
    {
        $restoreJob = BackupRestoreJob::query()->with(['backup', 'preRestoreBackup'])->findOrFail($this->restoreJobId);

        if ($restoreJob->mode !== RestoreMode::Production || $restoreJob->status !== RestoreStatus::Running) {
            return; // not in the expected state — never blindly re-execute
        }

        $backup = $restoreJob->backup;
        $workFiles = [];
        $started = hrtime(true);

        try {
            $audit->record('restore.started', ['restore_job_id' => $restoreJob->id, 'backup_id' => $backup->public_id, 'reference' => $restoreJob->reference], $restoreJob, $backup->store_id);

            $workFiles[] = $artifact = $storage->retrieveToLocalPath($backup->storage_path);
            $workFiles[] = $plainSql = $codec->decode($artifact, (bool) $backup->is_encrypted, $backup->compression);

            $restorer->restore($plainSql);

            // The import replaced every table, including this module's own:
            // the rows describing this restore, the backup it used and the
            // safety backup are now as they were when the backup was taken,
            // or gone. Put them back so the restore stays on record and the
            // safety backup stays findable.
            foreach (array_filter([$backup, $restoreJob->preRestoreBackup, $restoreJob]) as $record) {
                $this->reinstate($record);
            }

            $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

            // Queued job: no tenant context — the event is attributed to
            // the backup's own store explicitly (ADR-004 transaction).
            DB::transaction(function () use ($restoreJob, $outbox, $durationMs) {
                $restoreJob->update(['status' => 'completed', 'completed_at' => now(), 'duration_ms' => $durationMs]);
                $outbox->recordEventFor(
                    $restoreJob->backup->store_id,
                    eventType: 'restore.completed',
                    payload: ['restore_job_id' => $restoreJob->id, 'backup_id' => $restoreJob->backup_id],
                    idempotencyKey: "restore_job:{$restoreJob->id}:completed",
                );
            });

            $audit->record('restore.completed', ['restore_job_id' => $restoreJob->id, 'backup_id' => $backup->public_id, 'reference' => $restoreJob->reference, 'duration_ms' => $durationMs], $restoreJob, $backup->store_id);
        } catch (\Throwable $e) {
            $reason = Str::limit($e->getMessage(), 240, '…');

            DB::transaction(function () use ($restoreJob, $backup, $outbox, $reason) {
                $restoreJob->update(['status' => 'failed', 'failure_reason' => $reason, 'completed_at' => now()]);
                $outbox->recordEventFor(
                    $backup->store_id,
                    eventType: 'restore.failed',
                    payload: [
                        'restore_job_id' => $restoreJob->id, 'backup_id' => $restoreJob->backup_id, 'backup_public_id' => $backup->public_id,
                        'reference' => $restoreJob->reference, 'reason' => $reason, 'request_id' => $restoreJob->request_id,
                        'pre_restore_backup_public_id' => $restoreJob->preRestoreBackup?->public_id,
                    ],
                    idempotencyKey: "restore_job:{$restoreJob->id}:failed",
                );
            });

            $audit->record('restore.failed', ['restore_job_id' => $restoreJob->id, 'backup_id' => $backup->public_id, 'reason' => $reason], $restoreJob, $backup->store_id);

            // Deliberately NOT re-thrown — Module 23 Phase 21 "never
            // silently leave the system in a falsely healthy state" is
            // satisfied by the explicit 'failed' status above; re-
            // throwing would only trigger Laravel's own failed-job
            // handling on top of the state this service already
            // recorded correctly, and tries=1 means no retry would
            // happen anyway.
        } finally {
            // A full database dump must never linger on local disk.
            foreach ($workFiles as $file) {
                @unlink($file);
            }

            if ($this->lockOwner !== null) {
                Cache::restoreLock(RestoreService::LOCK, $this->lockOwner)->release();
            }
        }
    }

    /** Writes the in-memory record back if the restored database no longer has it. */
    private function reinstate(Model $record): void
    {
        if (! DB::table($record->getTable())->where('id', $record->getKey())->exists()) {
            DB::table($record->getTable())->insert($record->getRawOriginal());
        }
    }
}
