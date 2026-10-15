<?php

declare(strict_types=1);

namespace App\Domain\Help\Console;

use App\Domain\Help\Services\HelpBundleSync;
use App\Domain\Help\Support\HelpBundle;
use Illuminate\Console\Command;

/**
 * Phase B54 (Module 34 §57 "documentation CI", §73.14–73.17) — checks the
 * help content in the repository without touching the database: metadata,
 * links to articles, admin and storefront pages, developer API paths and
 * webhook events against the code, troubleshooting structure, release
 * note versions, and secret and personal-data scanning. Runs in CI.
 */
final class CheckHelpCommand extends Command
{
    protected $signature = 'help:check';

    protected $description = 'Check the help articles and release notes in resources/help (CI).';

    public function handle(HelpBundleSync $sync): int
    {
        $bundle = HelpBundle::load();
        $problems = $sync->problems($bundle);
        if ($problems !== []) {
            $this->error(count($problems).' problem(s) in resources/help:');
            foreach ($problems as $problem) {
                $this->line("  - {$problem}");
            }

            return self::FAILURE;
        }
        $this->info(sprintf('resources/help is valid: %d articles, %d release notes.', count($bundle->articles), count($bundle->releases)));

        return self::SUCCESS;
    }
}
