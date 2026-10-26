<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Services;

use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Loyalty\Models\LoyaltyEntry;
use App\Domain\Loyalty\Models\LoyaltyEntryType;
use App\Domain\Orders\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Phase P2 (Module 10 §45, Module 15 §45): the only writer of loyalty points.
 *
 * - tenant-scoped: accounts and entries carry the store and are read under
 *   the tenant scope;
 * - auditable: every change is an entry with its type, what it refers to,
 *   who did it and the balance after it; entries are never edited or deleted;
 * - never negative: the account row is locked for every change and the
 *   column is unsigned. A debit larger than the balance is refused —
 *   except a take-back (a refund after the points were used), which takes
 *   what is there and reports what it could not;
 * - idempotent: every change has a key; a repeat returns the entry that
 *   exists and moves nothing.
 */
final class LoyaltyLedger
{
    /** Types that count towards the tier (earned less taken back). */
    private const LIFETIME = [LoyaltyEntryType::Earned, LoyaltyEntryType::RepeatBonus, LoyaltyEntryType::Birthday, LoyaltyEntryType::Reversed];

    public function balance(Customer $customer): int
    {
        return (int) LoyaltyAccount::query()->where('customer_id', $customer->id)->value('balance');
    }

    public function account(Customer $customer): ?LoyaltyAccount
    {
        return LoyaltyAccount::query()->where('customer_id', $customer->id)->first();
    }

    /**
     * Moves points. A positive amount adds, a negative one takes.
     *
     * @param array{type: string, id: int}|null $reference
     * @param bool $partial take what there is when the balance is short (refund take-backs)
     *
     * @throws InsufficientPoints
     */
    public function move(Customer $customer, int $points, LoyaltyEntryType $type, string $idempotencyKey, ?array $reference = null, ?string $note = null, ?int $actorUserId = null, bool $partial = false): ?LoyaltyEntry
    {
        if ($points === 0) {
            throw new \InvalidArgumentException('A change of points cannot be zero.');
        }
        if ($existing = LoyaltyEntry::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        return DB::transaction(function () use ($customer, $points, $type, $idempotencyKey, $reference, $note, $actorUserId, $partial) {
            LoyaltyAccount::query()->firstOrCreate(['customer_id' => $customer->id]);
            $account = LoyaltyAccount::query()->where('customer_id', $customer->id)->lockForUpdate()->firstOrFail();
            if ($existing = LoyaltyEntry::query()->where('idempotency_key', $idempotencyKey)->first()) {
                return $existing;
            }

            $requested = $points;
            if ($account->balance + $points < 0) {
                if (! $partial) {
                    throw new InsufficientPoints(-$points, $account->balance);
                }
                $points = -$account->balance;
                if ($points === 0) {
                    return null;
                }
            }
            $balance = $account->balance + $points;
            // A take-back lowers the tier by all it takes back, also the part the balance could not cover.
            $lifetime = in_array($type, self::LIFETIME, true) ? max(0, $account->lifetime_points + $requested) : $account->lifetime_points;
            $account->forceFill([
                'balance' => $balance,
                'lifetime_points' => $lifetime,
                // Expiry counts from the customer's last earning or use, not from staff corrections or expiry itself.
                'last_activity_at' => in_array($type, [LoyaltyEntryType::Earned, LoyaltyEntryType::RepeatBonus, LoyaltyEntryType::Birthday, LoyaltyEntryType::Redeemed], true) ? now() : $account->last_activity_at,
            ])->save();

            return LoyaltyEntry::query()->create([
                'account_id' => $account->id,
                'customer_id' => $customer->id,
                'type' => $type,
                'points' => $points,
                'balance_after' => $balance,
                'reference_type' => $reference['type'] ?? null,
                'reference_id' => $reference['id'] ?? null,
                'note' => $note,
                'actor_user_id' => $actorUserId,
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    /** Module 32: the customer's data is erased; the points end with the account, on the record. */
    public function endForErasedCustomer(Customer $customer): int
    {
        $balance = $this->balance($customer);
        if ($balance > 0) {
            $this->move($customer, -$balance, LoyaltyEntryType::Erased, "customer:{$customer->id}:loyalty-erased", null, 'Personal data erased');
        }

        return $balance;
    }
}
