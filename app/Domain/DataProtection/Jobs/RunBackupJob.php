<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Jobs;

use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Services\BackupService;
use App\Domain\DataProtection\Services\DumpStrategies\DatabaseDumpStrategy;
use App\Domain\DataProtection\Services\Storage\BackupStorageAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Module 23 Phase 12 "Backup Jobs" — payload carries ONLY the backup's
 * own numeric id (never a store_id trusted from the payload directly —
 * BackupService::execute() re-reads the Backup row's OWN store_id/
 * status from the database, the single source of truth). Idempotent
 * (see BackupService::execute()'s own status guard) and retry-safe
 * (Laravel's queue backoff below).
 */
final class RunBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $backupId) {}

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(BackupService $backups, DatabaseDumpStrategy $dumper, BackupStorageAdapter $storage): void
    {
        $backup = Backup::query()->findOrFail($this->backupId);

        $backups->execute($backup, $dumper, $storage);
    }
}
