<?php

declare(strict_types=1);

namespace App\Domain\Affiliates\Console;

use App\Domain\Affiliates\Services\AffiliateLedger;
use Illuminate\Console\Command;

/**
 * Phase B57 (Module 28 §22–24, §62): brings every open commission in line
 * with its order — cancelled, refunded, delivered and paid, hold period
 * over. Idempotent: it reads the orders as they are and changes only what
 * differs, so running it again changes nothing.
 */
final class FollowUpCommissionsCommand extends Command
{
    protected $signature = 'affiliates:follow-up';

    protected $description = 'Approve, reverse or reject affiliate commissions as their orders are delivered, refunded or cancelled.';

    public function handle(AffiliateLedger $ledger): int
    {
        $result = $ledger->followUp();
        $this->info("Commissions: {$result['approved']} approved, {$result['reversed']} reversed, {$result['rejected']} rejected.");

        return self::SUCCESS;
    }
}
