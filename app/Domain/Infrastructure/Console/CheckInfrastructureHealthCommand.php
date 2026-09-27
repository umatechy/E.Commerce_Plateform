<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Console;

use App\Domain\Infrastructure\Services\InfrastructureHealthService;
use Illuminate\Console\Command;

/**
 * Module 20 Phase 22 "Deployment Process" — the "HEALTH CHECK" /
 * "SMOKE TEST" step of the deployment pipeline. Exit code 0 = healthy
 * (safe to proceed), 1 = degraded (deployment script should stop and
 * alert, never silently continue).
 *
 * NOT EXECUTED — ENVIRONMENT LIMITATION: this command has never been
 * run against a real MySQL/Redis/filesystem in this Claude App sandbox.
 */
final class CheckInfrastructureHealthCommand extends Command
{
    protected $signature = 'infrastructure:health-check';

    protected $description = 'Verify database, cache, and storage connectivity — intended for deployment smoke tests.';

    public function handle(InfrastructureHealthService $health): int
    {
        $result = $health->check();

        foreach ($result['checks'] as $name => $check) {
            $line = "{$name}: {$check['status']}";
            $check['status'] === 'ok' ? $this->info($line) : $this->error($line);
        }

        if ($result['status'] !== 'ok') {
            $this->error('Infrastructure health check FAILED — deployment should not proceed.');

            return self::FAILURE;
        }

        $this->info('All infrastructure health checks passed.');

        return self::SUCCESS;
    }
}
