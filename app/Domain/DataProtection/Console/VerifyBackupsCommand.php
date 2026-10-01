<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Console;

use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupScope;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\DataProtection\Services\BackupService;
use App\Domain\DataProtection\Services\Storage\BackupStorageAdapter;
use App\Support\RequestId;
use Illuminate\Console\Command;

/**
 * Module 23 §15 "Backup Integrity" (SRS BKP-003): a backup that was good
 * when it was made can go bad in storage. This re-reads stored
 * artifacts and compares them with what was recorded. A backup that
 * fails is marked failed, so no restore can pick it, and a critical
 * alert is raised (BackupService::recheck).
 */
final class VerifyBackupsCommand extends Command
{
    protected $signature = 'backups:verify
        {--all : Every verified backup, not only the newest platform backup}
        {--deep : Also decrypt and decompress each artifact completely}';

    protected $description = 'Re-check stored backup artifacts against their recorded size and checksum.';

    public function handle(BackupService $backups, BackupStorageAdapter $storage): int
    {
        RequestId::begin('backup-verify');

        $query = Backup::query()->where('status', BackupStatus::Verified->value)->orderByDesc('verified_at');

        $selected = $this->option('all')
            ? $query->get()
            : $query->where('scope', BackupScope::Platform->value)->limit(1)->get();

        if ($selected->isEmpty()) {
            $this->warn('There is no verified backup to check.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($selected as $backup) {
            if ($backups->recheck($backup, $storage, (bool) $this->option('deep'))) {
                $this->info("{$backup->public_id}: intact.");
            } else {
                $failed++;
                $this->error("{$backup->public_id}: FAILED — {$backup->fresh()->failure_reason}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
