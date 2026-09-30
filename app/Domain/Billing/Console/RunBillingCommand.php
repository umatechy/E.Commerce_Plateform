<?php

declare(strict_types=1);

namespace App\Domain\Billing\Console;

use App\Domain\Billing\Services\BillingRunner;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;

final class RunBillingCommand extends Command
{
    protected $signature = 'billing:run';

    protected $description = 'Issue renewal invoices, renew paid periods and apply dunning to unpaid subscriptions (Module 29).';

    public function handle(BillingRunner $runner, TenantContext $context): int
    {
        // Platform-scope operation across every store (ADR-001 Layer 9);
        // each write names its own store explicitly.
        $context->resolveToPlatform();

        $summary = $runner->run();

        $this->table(['action', 'count'], collect($summary)->map(fn (int $count, string $action) => [$action, $count])->values()->all());

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
