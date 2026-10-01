<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\DataProtection\Exceptions\BackupIntegrityException;
use App\Domain\DataProtection\Jobs\RunBackupJob;
use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupInitiator;
use App\Domain\DataProtection\Models\BackupRetentionTier;
use App\Domain\DataProtection\Models\BackupScope;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\DataProtection\Services\DumpStrategies\DatabaseDumpStrategy;
use App\Domain\DataProtection\Services\Storage\BackupStorageAdapter;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Tenancy\Models\Store;
use App\Support\RequestId;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Module 23 Phase 4 "Backup Architecture" — the ONE orchestrator for
 * the full backup lifecycle. Reuses ConfigService (B17, through
 * BackupRetentionPolicy), RecordsOutboxEvents (ADR-004) and AuditLogger
 * (B22) — no duplicate configuration/eventing/audit system.
 *
 * Phase B30 (gap G4) added: scheduled backups that cannot be duplicated,
 * retention tiers, compressed and optionally encrypted artifacts, a
 * verification that re-reads the stored artifact, and a later re-check
 * that catches an artifact that went bad in storage.
 */
final class BackupService
{
    public function __construct(
        private readonly BackupStateMachine $stateMachine,
        private readonly RecordsOutboxEvents $outbox,
        private readonly BackupRetentionPolicy $retention,
        private readonly BackupArtifactCodec $codec,
        private readonly AuditLogger $audit,
    ) {}

    /** Module 23 Phase 13/19 "Manual Backups" — a store's own staff-requested backup, or a Super-Admin-requested platform backup. */
    public function requestBackup(
        BackupScope $scope,
        ?Store $store,
        BackupInitiator $initiator,
        ?int $userId,
        ?BackupRetentionTier $tier = null,
        ?string $scheduleKey = null,
    ): Backup {
        $tier ??= $initiator === BackupInitiator::PreRestoreSafety ? BackupRetentionTier::PreChange : BackupRetentionTier::Manual;

        // ADR-004: the row, its first transition and the outbox event
        // commit together. The job is dispatched only after the commit so
        // a real (non-sync) worker can never pick it up before the row
        // exists.
        $backup = DB::transaction(function () use ($scope, $store, $initiator, $userId, $tier, $scheduleKey) {
            $backup = Backup::query()->create([
                'public_id' => (string) Str::ulid(),
                'scope' => $scope,
                'store_id' => $store?->id,
                'status' => BackupStatus::Created,
                'initiated_by' => $initiator,
                'initiated_by_user_id' => $userId,
                'retention_tier' => $tier,
                'schedule_key' => $scheduleKey,
                'expires_at' => $this->retention->expiresAt($tier, now()),
                'request_id' => RequestId::current(),
            ]);

            $this->stateMachine->transition($backup, BackupStatus::Queued);

            $this->outbox->recordEventFor(
                $backup->store_id,
                eventType: 'backup.requested',
                payload: ['backup_id' => $backup->id, 'scope' => $scope->value, 'store_id' => $store?->id],
                idempotencyKey: "backup:{$backup->id}:requested",
            );

            return $backup;
        });

        $this->audit->record('backup.created', [
            'backup_id' => $backup->public_id, 'scope' => $scope->value, 'trigger' => $initiator->value,
            'retention_tier' => $tier->value, 'schedule_key' => $scheduleKey,
        ], $backup, $backup->store_id);

        RunBackupJob::dispatch($backup->id);

        return $backup;
    }

    /**
     * The scheduled platform backup for a day (Module 23 §18). Calling it
     * twice for the same day — an overlapping scheduler run, a second
     * server, a retry — returns the first backup: the unique schedule key
     * makes a second row impossible.
     *
     * @return array{backup: Backup, created: bool}
     */
    public function requestScheduled(Carbon $day): array
    {
        $slot = $this->retention->scheduledSlot($day);

        if ($existing = Backup::query()->where('schedule_key', $slot['key'])->first()) {
            return ['backup' => $existing, 'created' => false];
        }

        try {
            return ['backup' => $this->requestBackup(BackupScope::Platform, null, BackupInitiator::Scheduled, null, $slot['tier'], $slot['key']), 'created' => true];
        } catch (UniqueConstraintViolationException) {
            // Another scheduler created it between the check and the insert.
            return ['backup' => Backup::query()->where('schedule_key', $slot['key'])->firstOrFail(), 'created' => false];
        }
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
        // The claim is one conditional UPDATE: of two workers holding the
        // same job, exactly one moves the row from queued to running.
        $claimed = Backup::query()->whereKey($backup->id)->where('status', BackupStatus::Queued->value)
            ->update(['status' => BackupStatus::Running->value, 'started_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $backup->refresh();
        $clock = hrtime(true);
        $stage = 'dump';
        $workFiles = [];

        try {
            $workFiles[] = $dumpPath = $dumper->dump();

            $stage = 'encode';
            $artifact = $this->codec->encode($dumpPath);
            $workFiles[] = $artifact['path'];
            $checksum = hash_file('sha256', $artifact['path']);
            $size = filesize($artifact['path']);

            $stage = 'store';
            $storagePath = $this->generateStoragePath($backup, $artifact['encrypted']);
            $storage->store($artifact['path'], $storagePath);

            $backup->update([
                'storage_disk' => $storage->diskName(), 'storage_path' => $storagePath,
                'size_bytes' => $size, 'checksum_sha256' => $checksum,
                'compression' => $artifact['compression'], 'is_encrypted' => $artifact['encrypted'],
                'completed_at' => now(),
                'manifest' => [
                    ...$this->buildManifest($backup, $size, $checksum, $artifact['compression'], $artifact['encrypted']),
                    // Dump, encode and upload, measured here: the timestamps only have second precision.
                    'duration_ms' => $durationMs = (int) ((hrtime(true) - $clock) / 1_000_000),
                ],
            ]);

            $this->audit->record('backup.completed', [
                'backup_id' => $backup->public_id, 'size_bytes' => $size, 'encrypted' => $artifact['encrypted'],
                'duration_ms' => $durationMs,
            ], $backup, $backup->store_id);

            $stage = 'verify';
            $this->stateMachine->transition($backup, BackupStatus::Verifying);
            $this->verify($backup, $storage);
        } catch (\Throwable $e) {
            $reason = Str::limit($e->getMessage(), 240, '…');

            // Runs in a queued job, where no tenant context exists — the
            // event is attributed to the backup's own store explicitly.
            DB::transaction(function () use ($backup, $reason, $stage) {
                $backup->update(['failure_reason' => $reason]);
                $this->stateMachine->transition($backup, BackupStatus::Failed);

                $this->outbox->recordEventFor(
                    $backup->store_id,
                    eventType: 'backup.failed',
                    payload: [
                        'backup_id' => $backup->id, 'backup_public_id' => $backup->public_id, 'store_id' => $backup->store_id,
                        'scope' => $backup->scope->value, 'trigger' => $backup->initiated_by->value,
                        'stage' => $stage, 'reason' => $reason, 'request_id' => $backup->request_id,
                    ],
                    idempotencyKey: "backup:{$backup->id}:failed",
                );
            });

            $this->audit->record('backup.failed', ['backup_id' => $backup->public_id, 'stage' => $stage, 'reason' => $reason], $backup, $backup->store_id);

            throw $e; // lets the queue's own retry/backoff handle it
        } finally {
            // Local working copies of a full database dump must never
            // linger, success or failure.
            foreach ($workFiles as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Module 23 Phase 15 "Backup Integrity" — "a successful upload does
     * not equal a verified backup." Reads the STORED artifact back:
     * it must exist, have the recorded size and checksum, and decode
     * (decrypt, decompress) to something non-empty.
     *
     * @throws BackupIntegrityException
     */
    public function verify(Backup $backup, BackupStorageAdapter $storage): void
    {
        $this->assertArtifactIntact($backup, $storage, deep: true);

        DB::transaction(function () use ($backup) {
            $this->stateMachine->transition($backup, BackupStatus::Verified);
            $backup->update(['verified_at' => now(), 'last_checked_at' => now()]);

            $this->outbox->recordEventFor(
                $backup->store_id,
                eventType: 'backup.verified',
                payload: ['backup_id' => $backup->id, 'store_id' => $backup->store_id],
                idempotencyKey: "backup:{$backup->id}:verified",
            );
        });

        $this->audit->record('backup.verified', ['backup_id' => $backup->public_id, 'store_id' => $backup->store_id], $backup, $backup->store_id);
    }

    /**
     * A later check of a backup that was verified when it was made: the
     * artifact may since have been lost or damaged in storage. A backup
     * that fails is marked failed, so it can never be chosen for a
     * restore, and a critical alert is raised.
     *
     * @return bool whether the artifact is still intact
     */
    public function recheck(Backup $backup, BackupStorageAdapter $storage, bool $deep = false): bool
    {
        if ($backup->status !== BackupStatus::Verified) {
            return false;
        }

        try {
            $this->assertArtifactIntact($backup, $storage, $deep);
        } catch (\Throwable $e) {
            $reason = Str::limit('Integrity re-check failed: '.$e->getMessage(), 240, '…');

            DB::transaction(function () use ($backup, $reason) {
                $backup->update(['failure_reason' => $reason]);
                $this->stateMachine->transition($backup, BackupStatus::Failed);

                $this->outbox->recordEventFor(
                    $backup->store_id,
                    eventType: 'backup.verification_failed',
                    payload: [
                        'backup_id' => $backup->id, 'backup_public_id' => $backup->public_id, 'store_id' => $backup->store_id,
                        'scope' => $backup->scope->value, 'reason' => $reason, 'request_id' => RequestId::current(),
                    ],
                    idempotencyKey: "backup:{$backup->id}:verification_failed",
                );
            });

            $this->audit->record('backup.verification_failed', ['backup_id' => $backup->public_id, 'reason' => $reason], $backup, $backup->store_id);

            return false;
        }

        $backup->update(['last_checked_at' => now()]);

        return true;
    }

    /**
     * @param bool $deep  also decode the whole artifact (decrypt + decompress)
     * @throws BackupIntegrityException
     */
    public function assertArtifactIntact(Backup $backup, BackupStorageAdapter $storage, bool $deep): void
    {
        if ($backup->storage_path === null || ! $storage->exists($backup->storage_path)) {
            throw new BackupIntegrityException('Stored artifact is missing.');
        }

        if ($storage->size($backup->storage_path) !== $backup->size_bytes) {
            throw new BackupIntegrityException('Stored artifact size does not match the recorded size.');
        }

        if (! hash_equals((string) $backup->checksum_sha256, $storage->checksum($backup->storage_path))) {
            throw new BackupIntegrityException('Stored artifact checksum does not match the recorded checksum.');
        }

        if ($deep) {
            $local = $storage->retrieveToLocalPath($backup->storage_path);

            try {
                $this->codec->assertReadable($local, (bool) $backup->is_encrypted, $backup->compression);
            } finally {
                @unlink($local);
            }
        }
    }

    private function generateStoragePath(Backup $backup, bool $encrypted): string
    {
        // Always server-generated, opaque, never client-influenced
        // (Non-Negotiable, Phase 8/10) — includes the backup's own
        // ULID so a leaked path alone reveals nothing predictable about
        // sequence or count. No store id, no credentials, no dates.
        return "backups/{$backup->public_id}.sql.gz".($encrypted ? '.enc' : '');
    }

    private function buildManifest(Backup $backup, int $size, string $checksum, string $compression, bool $encrypted): array
    {
        // Module 23 §16 "Backup Manifest" — never includes secrets or
        // actual backup contents, only descriptive metadata.
        return [
            'backup_id' => $backup->public_id,
            'backup_type' => 'database',
            'scope' => $backup->scope->value,
            'trigger' => $backup->initiated_by->value,
            'retention_tier' => $backup->retention_tier->value,
            'source_version' => config('app.version', 'unknown'),
            'size_bytes' => $size,
            'checksum_sha256' => $checksum,
            'compression' => $compression,
            'encrypted' => $encrypted,
            'encryption' => $encrypted ? 'xchacha20-poly1305-secretstream' : null,
        ];
    }
}
