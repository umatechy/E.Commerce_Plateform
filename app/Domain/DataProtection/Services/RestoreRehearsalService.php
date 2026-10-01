<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\DataProtection\Exceptions\BackupNotRestoreEligibleException;
use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupRestoreJob;
use App\Domain\DataProtection\Models\BackupScope;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\DataProtection\Models\RestoreMode;
use App\Domain\DataProtection\Models\RestoreStatus;
use App\Domain\DataProtection\Services\Rehearsal\RehearsalTarget;
use App\Domain\DataProtection\Services\Storage\BackupStorageAdapter;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Support\RequestId;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Restore rehearsal (Module 23 §47 "Restore Testing"; SRS BKP-007,
 * TEST-011): proves that a backup can actually be restored, without
 * touching live data. The backup is checked, decoded, imported into a
 * throw-away target, inspected, and the target is destroyed.
 *
 * A rehearsal is recorded as a BackupRestoreJob with mode "rehearsal".
 * Its result is whatever really happened: a rehearsal that could not
 * run is "failed" with the reason, never "completed".
 *
 * The measured duration is evidence towards the RTO target. It is the
 * time to verify, decode and import this backup on this server — not a
 * guarantee for another server or a larger database.
 */
final class RestoreRehearsalService
{
    /** One rehearsal at a time; also keeps retention cleanup away while one runs. */
    public const LOCK = 'backups:rehearsal';

    public function __construct(
        private readonly BackupService $backups,
        private readonly BackupStorageAdapter $storage,
        private readonly BackupArtifactCodec $codec,
        private readonly RehearsalTarget $target,
        private readonly RecordsOutboxEvents $outbox,
        private readonly AuditLogger $audit,
    ) {}

    /** The newest verified platform backup: the one a real recovery would start from. */
    public function latestRehearsable(): ?Backup
    {
        return Backup::query()
            ->where('scope', BackupScope::Platform->value)
            ->where('status', BackupStatus::Verified->value)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('verified_at')
            ->first();
    }

    /**
     * @throws BackupNotRestoreEligibleException when another rehearsal is running
     */
    public function rehearse(Backup $backup, ?int $requestedByUserId): BackupRestoreJob
    {
        $lock = Cache::lock(self::LOCK, (int) config('backup.process_timeout', 3600) + 600);

        if (! $lock->get()) {
            throw new BackupNotRestoreEligibleException('another restore rehearsal is in progress');
        }

        try {
            return $this->run($backup, $requestedByUserId);
        } finally {
            $lock->release();
        }
    }

    private function run(Backup $backup, ?int $requestedByUserId): BackupRestoreJob
    {
        $rehearsal = BackupRestoreJob::query()->create([
            'backup_id' => $backup->id,
            'mode' => RestoreMode::Rehearsal,
            'requested_by_user_id' => $requestedByUserId,
            'status' => RestoreStatus::Running,
            'started_at' => now(),
            'request_id' => RequestId::current(),
        ]);

        $this->audit->record('restore.rehearsal_started', ['restore_job_id' => $rehearsal->id, 'backup_id' => $backup->public_id], $rehearsal, $backup->store_id);

        $started = hrtime(true);
        $workFiles = [];
        $report = ['backup_id' => $backup->public_id, 'rto_target_hours' => (int) config('backup.rto_hours')];

        try {
            if (! $backup->isRestoreEligible()) {
                throw new BackupNotRestoreEligibleException("backup status is \"{$backup->status->value}\" or it has expired");
            }

            // 1. Integrity, from storage: present, right size, right checksum.
            $this->backups->assertArtifactIntact($backup, $this->storage, deep: false);

            // 2. Decode (decrypt, decompress) to the plain dump.
            $workFiles[] = $artifact = $this->storage->retrieveToLocalPath($backup->storage_path);
            $workFiles[] = $plainSql = $this->codec->decode($artifact, (bool) $backup->is_encrypted, $backup->compression);
            $report['prepare_ms'] = $this->elapsedMs($started);

            // 3–5. Import into the isolated target, inspect it, destroy it.
            $report = [...$report, ...$this->target->rehearse($plainSql)];
        } catch (\Throwable $e) {
            return $this->finish($rehearsal, $backup, $report, $started, Str::limit($e->getMessage(), 240, '…'));
        } finally {
            foreach ($workFiles as $file) {
                @unlink($file);
            }
        }

        $failed = collect($report['checks'])->where('passed', false)->pluck('name')->all();

        return $this->finish($rehearsal, $backup, $report, $started, $failed === [] ? null : 'Checks failed: '.implode(', ', $failed));
    }

    /** @param array<string, mixed> $report */
    private function finish(BackupRestoreJob $rehearsal, Backup $backup, array $report, int $started, ?string $failure): BackupRestoreJob
    {
        $durationMs = $this->elapsedMs($started);
        $report['duration_ms'] = $durationMs;
        $report['within_rto_target'] = $failure === null ? $durationMs <= (int) config('backup.rto_hours') * 3_600_000 : null;
        $status = $failure === null ? RestoreStatus::Completed : RestoreStatus::Failed;

        DB::transaction(function () use ($rehearsal, $backup, $report, $status, $failure, $durationMs) {
            $rehearsal->update([
                'status' => $status, 'failure_reason' => $failure, 'report' => $report,
                'completed_at' => now(), 'duration_ms' => $durationMs,
            ]);

            if ($failure !== null) {
                $this->outbox->recordEventFor(
                    $backup->store_id,
                    eventType: 'restore.rehearsal_failed',
                    payload: [
                        'restore_job_id' => $rehearsal->id, 'backup_id' => $backup->id, 'backup_public_id' => $backup->public_id,
                        'reason' => $failure, 'request_id' => $rehearsal->request_id,
                    ],
                    idempotencyKey: "restore_job:{$rehearsal->id}:rehearsal_failed",
                );
            }
        });

        $this->audit->record($failure === null ? 'restore.rehearsal_completed' : 'restore.rehearsal_failed', [
            'restore_job_id' => $rehearsal->id, 'backup_id' => $backup->public_id, 'duration_ms' => $durationMs,
            ...($failure === null ? [] : ['reason' => $failure]),
        ], $rehearsal, $backup->store_id);

        return $rehearsal->fresh();
    }

    private function elapsedMs(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
