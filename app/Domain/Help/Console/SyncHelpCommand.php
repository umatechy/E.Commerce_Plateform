<?php

declare(strict_types=1);

namespace App\Domain\Help\Console;

use App\Domain\Help\Services\HelpBundleSync;
use Illuminate\Console\Command;

/**
 * Phase B54 (Module 34 §58–59) — loads resources/help into the help
 * center. Run after every deployment (docs/operations/deployment-checklist
 * and the seeders); safe to run again: unchanged files do nothing.
 */
final class SyncHelpCommand extends Command
{
    protected $signature = 'help:sync';

    protected $description = 'Load the help articles and release notes kept in resources/help into the help center.';

    public function handle(HelpBundleSync $sync): int
    {
        $result = $sync->sync();
        if ($result['problems'] !== []) {
            $this->error('Nothing was loaded. Fix these first:');
            foreach ($result['problems'] as $problem) {
                $this->line("  - {$problem}");
            }

            return self::FAILURE;
        }
        $this->info(sprintf(
            'Help center: %d new, %d updated, %d unchanged, %d changed here and kept (newer built-in text marked), %d archived; %d release notes loaded.',
            $result['created'], $result['updated'], $result['unchanged'], $result['kept_local'], $result['archived'], $result['releases'],
        ));

        return self::SUCCESS;
    }
}
