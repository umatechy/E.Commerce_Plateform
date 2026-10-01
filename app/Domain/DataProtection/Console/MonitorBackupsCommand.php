<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Console;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\DataProtection\Models\BackupInitiator;
use App\Domain\DataProtection\Services\BackupStatusReport;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Settings\Services\ConfigService;
use App\Support\RequestId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Module 23 "Backup Monitoring" / SRS HEALTH-005: notices the two
 * conditions no single failed job reports.
 *
 * - Overdue: there is no verified platform backup newer than the RPO
 *   target. This also catches a scheduler or queue that has stopped,
 *   when no backup job fails because none runs.
 * - Repeated failure: the last scheduled backups all failed.
 *
 * Each condition raises one critical alert per day (the event's
 * idempotency key), however often this runs. It decides nothing itself:
 * the figures come from BackupStatusReport, the same ones the Super
 * Admin summary shows.
 */
final class MonitorBackupsCommand extends Command
{
    protected $signature = 'backups:monitor';

    protected $description = 'Raise a critical alert when backups are overdue or keep failing.';

    public function handle(BackupStatusReport $report, ConfigService $config, RecordsOutboxEvents $outbox, AuditLogger $audit): int
    {
        RequestId::begin('backup-monitor');

        if ($config->get('backup.automated_backups_enabled') !== true) {
            $this->warn('Automated backups are switched off. Nothing to monitor.');

            return self::SUCCESS;
        }

        $status = $report->platform();
        $today = now('UTC')->toDateString();
        $problems = 0;

        if ($status['overdue']) {
            $problems++;
            $payload = [
                'rpo_target_hours' => $status['rpo_target_hours'],
                'last_verified_at' => $status['last_verified_at'],
                'hours_since_last_verified' => $status['hours_since_last_verified'],
                'request_id' => RequestId::current(),
            ];

            $this->error('Backup overdue: no verified platform backup within the RPO target of '.$status['rpo_target_hours'].' hours.');
            // Audited and alerted once a day, not once an hour.
            if (DB::transaction(fn () => $outbox->recordEventOnceFor(null, 'backup.overdue', $payload, "backup:overdue:{$today}"))) {
                $audit->record('backup.overdue', $payload, platform: true);
            }
        }

        $threshold = (int) config('backup.repeated_failure_threshold', 2);

        if ($status['consecutive_scheduled_failures'] >= $threshold) {
            $problems++;
            $payload = [
                'consecutive_failures' => $status['consecutive_scheduled_failures'],
                'reason' => $status['last_failure_reason'],
                'trigger' => BackupInitiator::Scheduled->value,
                'request_id' => RequestId::current(),
            ];

            $this->error("Repeated failure: the last {$status['consecutive_scheduled_failures']} scheduled backups failed.");
            if (DB::transaction(fn () => $outbox->recordEventOnceFor(null, 'backup.repeated_failure', $payload, "backup:repeated_failure:{$today}"))) {
                $audit->record('backup.repeated_failure', $payload, platform: true);
            }
        }

        if ($problems === 0) {
            $this->info('Backups are on schedule.');
        }

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }
}
