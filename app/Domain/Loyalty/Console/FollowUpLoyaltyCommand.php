<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Console;

use App\Domain\Loyalty\Services\LoyaltyEngine;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase P2: for every store with a loyalty program, points follow the
 * orders (pending, earned, taken back on refunds), birthdays are rewarded
 * and inactive balances expire where the store set it. Idempotent — safe
 * to run again; each store runs under its own tenant context.
 */
final class FollowUpLoyaltyCommand extends Command
{
    protected $signature = 'loyalty:follow-up';

    protected $description = 'Award, settle and take back loyalty points as orders move; birthday rewards; expiry.';

    public function handle(TenantContext $context, LoyaltyEngine $engine): int
    {
        $totals = [];
        foreach (DB::table('loyalty_programs')->orderBy('store_id')->pluck('store_id') as $storeId) {
            $result = $context->asStore((int) $storeId, fn () => $engine->run());
            foreach ($result as $key => $count) {
                $totals[$key] = ($totals[$key] ?? 0) + $count;
            }
        }
        $this->info('Loyalty: '.collect($totals)->map(fn ($n, $k) => "{$n} {$k}")->implode(', ').'.');

        return self::SUCCESS;
    }
}
