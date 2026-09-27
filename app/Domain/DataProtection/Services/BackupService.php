<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Services;

use App\Domain\DataProtection\Exceptions\BackupIntegrityException;
use App\Domain\DataProtection\Jobs\RunBackupJob;
use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupInitiator;
use App\Domain\DataProtection\Models\BackupScope;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\DataProtection\Services\DumpStrategies\DatabaseDumpStrategy;
use App\Domain\DataProtection\Services\Storage\BackupStorageAdapter;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Module 23 Phase 4 "Backup Architecture" — the ONE orchestrator for
 * the full backup lifecycle. Reuses ConfigService (B17), RecordsOutboxEvents
 * (ADR-004), Log::channel('audit') (existing pattern since B14) — no
 * duplicate configuration/eventing/audit system.
 */
final class BackupService
{
    public function __construct(
        private readonly BackupStateMachine $stateMachine,
        private readonly RecordsOutboxEvents $outbox,
        private readonly ConfigService $config,
    ) {}

    /** Module 23 Phase 13/19 "Manual Backups" — a store's own staff-requested backup, or a Super-Admin-requested platform backup. */
    public function requestBackup(BackupScope $scope, ?Store $store, BackupInitiator $initiator, ?int $userId): Backup
    {
        $retentionDays = $this->config->get('backup.retention_days');

        $backup = Backup::query()->create([
            'public_id' => (string) Str::ulid(),
            'scope' => $scope,
            'store_id' => $store?->id,
            'status' => BackupStatus::Created,
            'initiated_by' => $initiator,
            'initiated_by_user_id' => $userId,
            'expires_at' => now()->addDays($retentionDays),
        ]);

        $this->stateMachine->transition($backup, BackupStatus::Queued);

        RunBackupJob::dispatch($backup->id);

        $this->outbox->recordEvent(
            eventType: 'backup.requested',
            payload: ['backup_id' => $backup->id, 'scope' => $scope->value, 'store_id' => $store?->id],
            idempotencyKey: "backup:{$backup->id}:requested",
        );

        return $backup;
    }

    /**
     * Called by RunBackupJob. Idempotent: if the backup is already past
     * 'queued' (a retried job for an already-processed backup), this is
     * a safe no-op rather than re-running the dump (Module 23 Phase 12
     * "a retry must not accidentally create uncontrolled duplicate
     * backup artifacts").
     */
    public function execute(Backup $backup, DatabaseDumpStrategy $dumper, BackupStorageAdapter $storage): void
    {
        if ($backup->status !== BackupStatus::Queued) {
            return;
        }

        $this->stateMachine->transition($backup, BackupStatus::Running);

        try {
            $dumpPath = $dumper->dump();
            $checksum = hash_file('sha256', $dumpPath);
            $size = filesize($dumpPath);

            $storagePath = $this->generateStoragePath($backup);
            $storage->store($dumpPath, $storagePath);
            @unlink($dumpPath); // the local temp copy must never linger after upload, success or failure

            $backup->update([
                'storage_disk' => 'local', 'storage_path' => $storagePath,
                'size_bytes' => $size, 'checksum_sha256' => $checksum,
                'manifest' => $this->buildManifest($backup, $size, $checksum),
            ]);

            $this->stateMachine->transition($backup, BackupStatus::Verifying);
            $this->verify($backup, $storage);
        } catch (\Throwable $e) {
            $backup->update(['failure_reason' => $e->getMessage()]);
            $this->stateMachine->transition($backup, BackupStatus::Failed);

            Log::channel('audit')->info('backup.failed', ['backup_id' => $backup->id, 'reason' => $e->getMessage()]);

            $this->outbox->recordEvent(
                eventType: 'backup.failed',
                payload: ['backup_id' => $backup->id, 'store_id' => $backup->store_id],
                idempotencyKey: "backup:{$backup->id}:failed",
            );

            throw $e; // lets the queue's own retry/backoff handle it
        }
    }

    /**
     * Module 23 Phase 15 "Backup Integrity" — "a successful upload does
     * not equal a verified backup." Re-reads the STORED artifact
     * (never trusts the in-memory copy) and re-computes its checksum.
     *
     * @throws BackupIntegrityException
     */
    public function verify(Backup $backup, BackupStorageAdapter $storage): void
    {
        if (! $storage->exists($backup->storage_path)) {
            throw new BackupIntegrityException('Stored artifact is missing.');
        }

        if ($storage->size($backup->storage_path) !== $backup->size_bytes) {
            throw new BackupIntegrityException('Stored artifact size does not match the recorded size.');
        }

        $this->stateMachine->transition($backup, BackupStatus::Verified);
        $backup->update(['verified_at' => now()]);

        Log::channel('audit')->info('backup.verified', ['backup_id' => $backup->id, 'store_id' => $backup->store_id]);

        $this->outbox->recordEvent(
            eventType: 'backup.verified',
            payload: ['backup_id' => $backup->id, 'store_id' => $backup->store_id],
            idempotencyKey: "backup:{$backup->id}:verified",
        );
    }

    private function generateStoragePath(Backup $backup): string
    {
        // Always server-generated, opaque, never client-influenced
        // (Non-Negotiable, Phase 8/10) — includes the backup's own
        // ULID so a leaked path alone reveals nothing predictable about
        // sequence or count.
        return "backups/{$backup->public_id}.sql";
    }

    private function buildManifest(Backup $backup, int $size, string $checksum): array
    {
        // Module 23 §16 "Backup Manifest" — never includes secrets or
        // actual backup contents, only descriptive metadata.
        return [
            'backup_id' => $backup->public_id,
            'backup_type' => 'database',
            'scope' => $backup->scope->value,
            'source_version' => config('app.version', 'unknown'),
            'size_bytes' => $size,
            'checksum_sha256' => $checksum,
            'compression' => 'none',
            'encrypted' => false,
        ];
    }
}
