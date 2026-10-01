<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Console;

use App\Domain\DataProtection\Exceptions\BackupNotRestoreEligibleException;
use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\RestoreStatus;
use App\Domain\DataProtection\Services\RestoreRehearsalService;
use App\Domain\Settings\Services\ConfigService;
use App\Support\RequestId;
use Illuminate\Console\Command;

/**
 * Module 23 §47 "Restore Testing" (SRS BKP-007, TEST-011): restores a
 * backup into a throw-away database, checks it and drops it. Live data
 * is never written. Run weekly by the scheduler; can be run by hand.
 *
 * The exit code is the truth: non-zero when the rehearsal failed or
 * could not run.
 */
final class RehearseRestoreCommand extends Command
{
    protected $signature = 'backups:rehearse
        {--backup= : The public id of the backup to rehearse (default: the newest verified platform backup)}
        {--force : Run even when backup.rehearsal_enabled is off}';

    protected $description = 'Rehearse a restore in an isolated database and report what was verified.';

    public function handle(RestoreRehearsalService $rehearsals, ConfigService $config): int
    {
        RequestId::begin('backup-rehearsal');

        if ($config->get('backup.rehearsal_enabled') !== true && ! $this->option('force')) {
            $this->warn('Restore rehearsals are switched off (backup.rehearsal_enabled). Nothing was run.');

            return self::SUCCESS;
        }

        $backup = $this->option('backup')
            ? Backup::query()->where('public_id', (string) $this->option('backup'))->first()
            : $rehearsals->latestRehearsable();

        if ($backup === null) {
            $this->error('There is no verified backup to rehearse.');

            return self::FAILURE;
        }

        try {
            $rehearsal = $rehearsals->rehearse($backup, null);
        } catch (BackupNotRestoreEligibleException $e) {
            $this->error('The rehearsal did not start: '.$e->getMessage());

            return self::FAILURE;
        }

        $report = (array) $rehearsal->report;

        $this->line("Backup:   {$backup->public_id}");
        $this->line('Duration: '.round(($rehearsal->duration_ms ?? 0) / 1000, 1).' s (import '.round(($report['import_ms'] ?? 0) / 1000, 1).' s)');

        foreach ((array) ($report['checks'] ?? []) as $check) {
            $this->line(($check['passed'] ? '  PASS  ' : '  FAIL  ').$check['name'].' — '.$check['detail']);
        }

        if ($rehearsal->status !== RestoreStatus::Completed) {
            $this->error('REHEARSAL FAILED: '.$rehearsal->failure_reason);

            return self::FAILURE;
        }

        $this->info('Rehearsal passed. Live data was not touched.');

        return self::SUCCESS;
    }
}
