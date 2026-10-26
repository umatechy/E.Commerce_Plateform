<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Services;

use App\Domain\Loyalty\Models\LoyaltyEntryType;
use App\Domain\Orders\Models\Customer;
use App\Domain\StoreCredit\Models\StoreCreditEntry;
use App\Domain\StoreCredit\Models\StoreCreditEntryType;
use App\Domain\StoreCredit\Services\StoreCreditService;
use Illuminate\Support\Facades\DB;

/**
 * Phase P2 (Module 14 §76): points become money only here — the customer
 * turns whole blocks of points into store credit, at the store's rate. The
 * points leave the points ledger and the credit enters the store credit
 * ledger in one transaction, each entry pointing at the other's reason, so
 * checkout and refunds only ever deal with store credit, and loyalty stays
 * apart from the promotion rules.
 *
 * Customers keep the value they earned: redeeming works while the store has
 * a program, also when it has turned earning off or its package changed.
 */
final class LoyaltyRedemption
{
    public function __construct(
        private readonly LoyaltyLedger $ledger,
        private readonly LoyaltyProgramService $programs,
        private readonly StoreCreditService $credit,
    ) {}

    /** @throws LoyaltyRefused */
    public function redeem(Customer $customer, int $points, string $idempotencyKey): StoreCreditEntry
    {
        $program = $this->programs->current() ?? throw new LoyaltyRefused('no_program', 'This store has no loyalty program.');
        if ($customer->erased_at !== null) {
            throw new LoyaltyRefused('erased', 'This account was erased.');
        }
        if ($points < $program->min_redeem_points) {
            throw new LoyaltyRefused('below_minimum', "You can use points from {$program->min_redeem_points} points.");
        }
        if ($points % $program->redeem_points !== 0) {
            throw new LoyaltyRefused('not_a_block', "Points are used in steps of {$program->redeem_points}.");
        }
        $key = "redeem:{$customer->id}:{$idempotencyKey}";

        return DB::transaction(function () use ($customer, $points, $program, $key) {
            try {
                $entry = $this->ledger->move($customer, -$points, LoyaltyEntryType::Redeemed, $key, null, 'Turned into store credit');
            } catch (InsufficientPoints $e) {
                throw new LoyaltyRefused('insufficient_points', "You have {$e->balance} points.");
            }
            if ($entry === null) { // only a partial take-back returns none; this one is not partial
                throw new LoyaltyRefused('insufficient_points', 'You have no points to use.');
            }

            return $this->credit->credit($customer, $program->currency, $program->valueOf(-$entry->points), StoreCreditEntryType::LoyaltyPoints, $key,
                ['type' => 'loyalty_entry', 'id' => $entry->id], number_format(-$entry->points).' loyalty points');
        });
    }
}
