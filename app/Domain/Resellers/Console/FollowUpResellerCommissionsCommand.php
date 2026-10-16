<?php

declare(strict_types=1);

namespace App\Domain\Resellers\Console;

use App\Domain\Resellers\Services\ResellerLedger;
use Illuminate\Console\Command;

/**
 * Phase B58 (Module 28 §21–25, §62): reseller commissions from paid
 * Umar Techy invoices, refunds by credit note taken back, holds ended.
 * Idempotent: one commission per invoice; running it again changes nothing.
 */
final class FollowUpResellerCommissionsCommand extends Command
{
    protected $signature = 'resellers:follow-up';

    protected $description = 'Create reseller commissions from paid invoices, take back refunds and approve commissions whose hold ended.';

    public function handle(ResellerLedger $ledger): int
    {
        $result = $ledger->followUp();
        $this->info("Reseller commissions: {$result['created']} created, {$result['approved']} approved, {$result['reversed']} reversed.");

        return self::SUCCESS;
    }
}
