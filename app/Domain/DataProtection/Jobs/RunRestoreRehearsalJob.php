<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Jobs;

use App\Domain\DataProtection\Exceptions\BackupNotRestoreEligibleException;
use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Services\RestoreRehearsalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A restore rehearsal asked for from the Super Admin screen. The
 * payload is the backup's id only; the backup, its scope and its state
 * are read again here. tries=1: a rehearsal that failed is a result to
 * look at, not something to repeat automatically.
 */
final class RunRestoreRehearsalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $backupId, public readonly ?int $requestedByUserId) {}

    public function handle(RestoreRehearsalService $rehearsals): void
    {
        $backup = Backup::query()->find($this->backupId);

        if ($backup === null) {
            return;
        }

        try {
            $rehearsals->rehearse($backup, $this->requestedByUserId);
        } catch (BackupNotRestoreEligibleException) {
            // Another rehearsal holds the lock. That one is the rehearsal.
        }
    }
}
