<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\DataProtection\Exceptions\BackupNotRestoreEligibleException;
use App\Domain\DataProtection\Jobs\RunRestoreJob;
use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupInitiator;
use App\Domain\DataProtection\Models\BackupRestoreJob;
use App\Domain\DataProtection\Models\BackupScope;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\DataProtection\Models\RestoreMode;
use App\Domain\DataProtection\Models\RestoreStatus;
use App\Domain\DataProtection\Services\DumpStrategies\DatabaseDumpStrategy;
use App\Domain\DataProtection\Services\Storage\BackupStorageAdapter;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Support\RequestId;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Module 23 Phase 16-19 "Restore Architecture / Authorization / Preflight
 * / Pre-Restore Safety" — Non-Negotiable: restore is HIGH RISK, never
 * a "download and overwrite" shortcut. See
 * docs/development/b19-inspection-findings.md "Architectural Decision
 * — Restore Is Platform-Level Only" — a Store Admin can REQUEST a
 * restore (this creates an auditable, preflight-checked record and
 * notifies Super Admin); only Super Admin can authorize + execute one,
 * since execution replaces the entire shared database.
 *
 * Phase B30 (gap G4): the preflight now reads the stored artifact
 * (present, right size, right checksum), a production restore needs an
 * explicit typed confirmation and an incident/change reference, and
 * only one production restore can run at a time.
 */
final class RestoreService
{
    /** Held from authorization until the restore job ends. */
    public const LOCK = 'backups:production-restore';

    public function __construct(
        private readonly RecordsOutboxEvents $outbox,
        private readonly BackupService $backups,
        private readonly BackupStorageAdapter $storage,
        private readonly AuditLogger $audit,
    ) {}

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
            'mode' => RestoreMode::Production,
            'target_store_id' => $targetStoreId,
            'requested_by_user_id' => $requestedByUserId,
            'status' => 'requested',
            'request_id' => RequestId::current(),
        ]);

        try {
            $this->preflight($backup);
        } catch (BackupNotRestoreEligibleException $e) {
            return $this->preflightFailed($restoreJob, $backup, $e->getMessage());
        }

        // The restore job row itself is already committed (a failed
        // preflight above must stay recorded); the event gets its own
        // transaction, as ADR-004's guard requires. Attributed to the
        // backup's store explicitly: a Super Admin may call this from
        // platform context.
        DB::transaction(fn () => $this->outbox->recordEventFor(
            $backup->store_id,
            eventType: 'restore.requested',
            payload: ['restore_job_id' => $restoreJob->id, 'backup_id' => $backup->id, 'target_store_id' => $targetStoreId],
            idempotencyKey: "restore_job:{$restoreJob->id}:requested",
        ));

        return $restoreJob;
    }

    /**
     * The backup itself: verified, not expired, and its stored artifact
     * is still there with the size and checksum recorded when it was made.
     *
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

        try {
            $this->backups->assertArtifactIntact($backup, $this->storage, deep: false);
        } catch (\Throwable $e) {
            throw new BackupNotRestoreEligibleException('backup artifact failed its integrity check: '.$e->getMessage());
        }
    }

    /**
     * Super-Admin-only action (enforced by the route's gates, MFA and
     * step-up middleware — this service trusts its caller exactly like
     * every other domain service in this codebase). Takes an immediate,
     * synchronous pre-restore safety backup (Module 23 Phase 19) BEFORE
     * the destructive restore is even queued — if the safety backup
     * itself fails, the restore never proceeds.
     *
     * Nothing is changed unless every check passes.
     *
     * @param string $confirmation  the backup's public id, typed by the person authorizing
     * @param string $reference     the incident or change this restore belongs to
     * @throws BackupNotRestoreEligibleException
     */
    public function authorizeAndExecute(
        BackupRestoreJob $restoreJob,
        int $authorizedByUserId,
        string $confirmation,
        string $reference,
        DatabaseDumpStrategy $dumper,
    ): void {
        $backup = $restoreJob->backup;

        if ($restoreJob->mode !== RestoreMode::Production) {
            throw new BackupNotRestoreEligibleException('this is a rehearsal record, not a production restore request');
        }

        if ($restoreJob->status !== RestoreStatus::Requested) {
            throw new BackupNotRestoreEligibleException("restore job status is \"{$restoreJob->status->value}\", not \"requested\"");
        }

        if (! hash_equals($backup->public_id, trim($confirmation))) {
            throw new BackupNotRestoreEligibleException('the confirmation does not match the backup id');
        }

        if (mb_strlen(trim($reference)) < 5) {
            throw new BackupNotRestoreEligibleException('an incident or change reference is required');
        }

        // One production restore at a time. The lock outlives this
        // request: RunRestoreJob releases it when the restore ends.
        $lock = Cache::lock(self::LOCK, (int) config('backup.process_timeout', 3600) + 600);

        if (! $lock->get()) {
            throw new BackupNotRestoreEligibleException('another production restore is in progress');
        }

        try {
            if (BackupRestoreJob::query()->where('mode', RestoreMode::Production->value)->where('status', RestoreStatus::Running->value)->exists()) {
                throw new BackupNotRestoreEligibleException('another production restore is in progress');
            }

            $this->preflight($backup);

            $preRestoreBackup = $this->backups->requestBackup(BackupScope::Platform, null, BackupInitiator::PreRestoreSafety, $authorizedByUserId);
            $this->backups->execute($preRestoreBackup->fresh(), $dumper, $this->storage);

            if ($preRestoreBackup->fresh()->status !== BackupStatus::Verified) {
                throw new BackupNotRestoreEligibleException('the pre-restore safety backup did not complete');
            }
        } catch (\Throwable $e) {
            $lock->release();
            $this->preflightFailed($restoreJob, $backup, Str::limit($e->getMessage(), 240, '…'), keepRequested: true);

            throw $e instanceof BackupNotRestoreEligibleException ? $e : new BackupNotRestoreEligibleException('the pre-restore safety backup failed: '.$e->getMessage());
        }

        $restoreJob->update([
            'pre_restore_backup_id' => $preRestoreBackup->id,
            'authorized_by_user_id' => $authorizedByUserId,
            'reference' => trim($reference),
            'status' => 'running',
            'started_at' => now(),
            'request_id' => RequestId::current() ?? $restoreJob->request_id,
        ]);

        $this->audit->record('restore.authorized', [
            'restore_job_id' => $restoreJob->id, 'backup_id' => $backup->public_id, 'reference' => trim($reference),
            'authorized_by_user_id' => $authorizedByUserId, 'pre_restore_backup_id' => $preRestoreBackup->public_id,
        ], $restoreJob, $backup->store_id);

        RunRestoreJob::dispatch($restoreJob->id, $lock->owner());
    }

    /**
     * @param bool $keepRequested  a refused authorization leaves the request open for another attempt
     */
    private function preflightFailed(BackupRestoreJob $restoreJob, Backup $backup, string $reason, bool $keepRequested = false): BackupRestoreJob
    {
        if (! $keepRequested) {
            $restoreJob->update(['status' => 'preflight_failed', 'failure_reason' => Str::limit($reason, 240, '…')]);
        }

        $this->audit->record('restore.preflight_failed', ['restore_job_id' => $restoreJob->id, 'backup_id' => $backup->public_id, 'reason' => $reason], $restoreJob, $backup->store_id);

        return $restoreJob->fresh();
    }
}
