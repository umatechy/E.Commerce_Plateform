<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Console;

use App\Domain\Marketing\Services\MarketingAutomationService;
use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase P4b: sends the automatic marketing messages that are due, store by
 * store under its own tenant context — only where the package includes
 * `marketing.advanced`. Safe to run again: each occasion is sent once.
 */
final class RunAutomationsCommand extends Command
{
    protected $signature = 'marketing:automations';

    protected $description = 'Send the welcome, post-purchase, win-back and birthday messages that are due.';

    public function handle(TenantContext $context): int
    {
        $sent = 0;
        foreach (DB::table('marketing_automations')->where('is_enabled', true)->distinct()->orderBy('store_id')->pluck('store_id') as $storeId) {
            $sent += (int) $context->asStore((int) $storeId, function () {
                if (! app(EntitlementService::class)->hasFeature(MarketingAutomationService::FEATURE)) {
                    return 0;
                }

                return array_sum(app(MarketingAutomationService::class)->run());
            });
        }
        $this->info("Automations: {$sent} message(s) sent.");

        return self::SUCCESS;
    }
}
