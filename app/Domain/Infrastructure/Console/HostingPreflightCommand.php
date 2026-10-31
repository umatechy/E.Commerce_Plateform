<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Console;

use App\Domain\Infrastructure\Services\HostingPreflight;
use Illuminate\Console\Command;

/** Exit code 0: nothing required is missing, so a deployment may go on. */
final class HostingPreflightCommand extends Command
{
    protected $signature = 'hosting:preflight';

    protected $description = 'Check what this server really offers before deploying to it. Prints no secrets.';

    public function handle(HostingPreflight $preflight): int
    {
        $rows = $preflight->run();
        foreach ($rows as $row) {
            $mark = $row['passed'] ? 'PASS' : ($row['required'] ? 'FAIL' : 'WARN');
            $this->line("{$mark}  {$row['name']}: {$row['detail']}");
        }

        $failed = array_filter($rows, fn (array $row) => $row['required'] && ! $row['passed']);
        if ($failed !== []) {
            $this->error(count($failed).' required check(s) failed. Do not deploy.');

            return self::FAILURE;
        }

        $this->info('Nothing required is missing.');

        return self::SUCCESS;
    }
}
