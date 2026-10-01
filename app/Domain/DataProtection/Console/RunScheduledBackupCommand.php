<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Console;

use App\Domain\DataProtection\Services\BackupService;
use App\Domain\Settings\Services\ConfigService;
use App\Support\RequestId;
use Illuminate\Console\Command;

/**
 * Module 23 §18 "Automated Backup Jobs" (SRS BKP-001): the daily
 * platform backup, started by the existing Laravel scheduler.
 *
 * The backup itself runs in RunBackupJob, on the existing queue. This
 * command only asks for it. Asking twice for the same day does nothing
 * the second time (BackupService::requestScheduled).
 */
final class RunScheduledBackupCommand extends Command
{
    protected $signature = 'backups:run-scheduled';

    protected $description = 'Request today\'s scheduled platform backup (daily; monthly on the 1st).';

    public function handle(BackupService $backups, ConfigService $config): int
    {
        RequestId::begin('backup-run');

        if ($config->get('backup.automated_backups_enabled') !== true) {
            $this->warn('Automated backups are switched off (backup.automated_backups_enabled). Nothing was requested.');

            return self::SUCCESS;
        }

        $result = $backups->requestScheduled(now('UTC'));
        $backup = $result['backup']->fresh();

        $this->info($result['created']
            ? "Requested {$backup->retention_tier->value} backup {$backup->public_id} (status: {$backup->status->value})."
            : "Today's backup already exists: {$backup->public_id} (status: {$backup->status->value}).");

        return self::SUCCESS;
    }
}
