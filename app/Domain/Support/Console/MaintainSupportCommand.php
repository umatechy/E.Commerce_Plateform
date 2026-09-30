<?php

declare(strict_types=1);

namespace App\Domain\Support\Console;

use App\Domain\Support\Services\SupportDeskService;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;

final class MaintainSupportCommand extends Command
{
    protected $signature = 'support:maintain';

    protected $description = 'Flag support tickets that missed their service level and close resolved tickets past the reopen window (Module 34).';

    public function handle(SupportDeskService $desk, TenantContext $context): int
    {
        $context->resolveToPlatform(); // every store's tickets; each write names its store
        $result = $desk->maintain();
        $this->info("Flagged {$result['breached']} overdue ticket(s); closed {$result['closed']} resolved ticket(s).");

        return self::SUCCESS;
    }
}
