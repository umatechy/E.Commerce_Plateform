<?php

declare(strict_types=1);

namespace App\Domain\StoreCredit\Console;

use App\Domain\StoreCredit\Models\StoreCreditAccount;
use App\Domain\StoreCredit\Services\StoreCreditService;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase B35 — Module 09 §52: store credit that reached its expiry date is
 * written off as an `expired` ledger entry. Only credit given while the store
 * had expiry turned on has a date, so for every other store this does
 * nothing. Safe to run again: each run takes only what is still left.
 */
final class ExpireStoreCreditCommand extends Command
{
    protected $signature = 'store-credit:expire';

    protected $description = 'Write off store credit that reached its expiry date.';

    public function handle(TenantContext $context, StoreCreditService $credit): int
    {
        $due = DB::table('store_credit_lots')->where('remaining_minor', '>', 0)->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->select('store_id', 'account_id')->distinct()->orderBy('store_id')->get();
        $total = 0;

        foreach ($due->groupBy('store_id') as $storeId => $accounts) {
            $context->resolveToStore((int) $storeId);
            foreach ($accounts as $row) {
                $account = StoreCreditAccount::query()->find($row->account_id);
                if ($account !== null) {
                    $total += $credit->expireDue($account) > 0 ? 1 : 0;
                }
            }
        }

        $this->info("Expired store credit on {$total} account(s).");

        return self::SUCCESS;
    }
}
