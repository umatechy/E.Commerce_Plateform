<?php

declare(strict_types=1);

namespace App\Domain\StoreCredit\Services;

use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\StoreCredit\Exceptions\InsufficientStoreCreditException;
use App\Domain\StoreCredit\Models\StoreCreditAccount;
use App\Domain\StoreCredit\Models\StoreCreditEntry;
use App\Domain\StoreCredit\Models\StoreCreditEntryType;
use App\Domain\StoreCredit\Models\StoreCreditLot;
use Illuminate\Support\Facades\DB;

/**
 * Module 09 §52 "Store Credit Foundation", Module 12 §13 (Phase B34):
 * the only writer of store credit.
 *
 * §52 asks that store credit be:
 * - tenant-scoped: accounts and entries carry the store and are read
 *   under the tenant scope; a customer's credit exists in their store
 *   only;
 * - auditable: every change is a ledger entry with its type, what it
 *   refers to, who did it and the balance after it; entries are never
 *   edited or deleted;
 * - non-negative: the account row is locked for every change, a debit
 *   larger than the balance is refused, and the column is unsigned;
 * - protected from unauthorized manipulation: balances change only
 *   through this service — by a return's refund, an order paid or
 *   cancelled, or a staff adjustment that has its own permission, a
 *   required reason and step-up (StoreCreditController);
 * - expiry-aware where applicable (Phase B35): every credit is a lot with
 *   its own expiry; spending takes from the lot that expires first. Expiry
 *   is off unless the store turns it on (`store_credit.expires`, the policy
 *   is the owner's); then credit given from that moment lapses after
 *   `store_credit.expiry_days`, written to the ledger by expireDue().
 *
 * Every change has an idempotency key: a repeat returns the entry that
 * exists and moves nothing.
 */
final class StoreCreditService
{
    /** What the customer has in this currency. */
    public function balance(Customer $customer, string $currency): int
    {
        return (int) StoreCreditAccount::query()->where('customer_id', $customer->id)->where('currency', strtoupper($currency))->value('balance_minor');
    }

    /**
     * Adds credit.
     *
     * @param array{type: string, id: int}|null $reference what it is for
     */
    public function credit(Customer $customer, string $currency, int $amountMinor, StoreCreditEntryType $type, string $idempotencyKey, ?array $reference = null, ?string $note = null, ?int $actorUserId = null): StoreCreditEntry
    {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('A credit must be a positive amount.');
        }

        return $this->move($customer, $currency, $amountMinor, $type, $idempotencyKey, $reference, $note, $actorUserId);
    }

    /**
     * Takes credit. Refused when the customer does not have that much.
     *
     * @param array{type: string, id: int}|null $reference
     *
     * @throws InsufficientStoreCreditException
     */
    public function debit(Customer $customer, string $currency, int $amountMinor, StoreCreditEntryType $type, string $idempotencyKey, ?array $reference = null, ?string $note = null, ?int $actorUserId = null): StoreCreditEntry
    {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('A debit must be a positive amount.');
        }

        return $this->move($customer, $currency, -$amountMinor, $type, $idempotencyKey, $reference, $note, $actorUserId);
    }

    /**
     * Module 12 §13: pays as much of the order as the customer's credit
     * covers. Returns the amount taken (0 when they have none). The
     * caller records it on the order and charges the payment for the rest.
     */
    public function spendOnOrder(Customer $customer, Order $order, ?int $actorUserId = null): int
    {
        return DB::transaction(function () use ($customer, $order, $actorUserId) {
            $account = $this->lockedAccount($customer, $order->currency);
            $amount = min($account->balance_minor, max(0, (int) $order->grand_total_minor));
            if ($amount === 0) {
                return 0;
            }

            $this->debit($customer, $order->currency, $amount, StoreCreditEntryType::Spent, "order:{$order->id}:store-credit", ['type' => 'order', 'id' => $order->id], "Order {$order->order_number}", $actorUserId);

            return $amount;
        });
    }

    /** The order was cancelled: the credit used on it goes back, once. */
    public function restoreForCancelledOrder(Order $order, ?int $actorUserId): ?StoreCreditEntry
    {
        if ((int) $order->store_credit_minor <= 0 || $order->customer === null) {
            return null;
        }
        // What returns of this order already paid back to credit is not paid back twice.
        $returned = (int) DB::table('return_requests')->where('order_id', $order->id)
            ->selectRaw("COALESCE(SUM(refunded_credit_minor - CASE WHEN refund_method = 'store_credit' THEN refunded_minor ELSE 0 END), 0) as total")->value('total');
        $amount = (int) $order->store_credit_minor - $returned;
        if ($amount <= 0) {
            return null;
        }

        return $this->credit($order->customer, $order->currency, $amount, StoreCreditEntryType::OrderCancelled, "order:{$order->id}:store-credit-restored", ['type' => 'order', 'id' => $order->id], "Order {$order->order_number} cancelled", $actorUserId);
    }

    /** Module 32: the customer's data is erased; the balance ends with the account, on the record. */
    public function endForErasedCustomer(Customer $customer): int
    {
        $ended = 0;
        foreach (StoreCreditAccount::query()->where('customer_id', $customer->id)->where('balance_minor', '>', 0)->get() as $account) {
            $this->debit($customer, $account->currency, $account->balance_minor, StoreCreditEntryType::Erased, "customer:{$customer->id}:erased:{$account->currency}", null, 'Personal data erased');
            $ended++;
        }

        return $ended;
    }

    /**
     * @param array{type: string, id: int}|null $reference
     */
    private function move(Customer $customer, string $currency, int $signedAmount, StoreCreditEntryType $type, string $idempotencyKey, ?array $reference, ?string $note, ?int $actorUserId): StoreCreditEntry
    {
        if ($existing = StoreCreditEntry::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        return DB::transaction(function () use ($customer, $currency, $signedAmount, $type, $idempotencyKey, $reference, $note, $actorUserId) {
            $account = $this->lockedAccount($customer, $currency);
            // Checked again under the lock: a concurrent identical request may have written it.
            if ($existing = StoreCreditEntry::query()->where('idempotency_key', $idempotencyKey)->first()) {
                return $existing;
            }

            $balance = $account->balance_minor + $signedAmount;
            if ($balance < 0) {
                throw new InsufficientStoreCreditException(abs($signedAmount), $account->balance_minor);
            }
            $account->forceFill(['balance_minor' => $balance])->save();

            $entry = StoreCreditEntry::query()->create([
                'account_id' => $account->id,
                'customer_id' => $customer->id,
                'type' => $type,
                'amount_minor' => $signedAmount,
                'balance_after_minor' => $balance,
                'currency' => $account->currency,
                'reference_type' => $reference['type'] ?? null,
                'reference_id' => $reference['id'] ?? null,
                'note' => $note,
                'actor_user_id' => $actorUserId,
                'idempotency_key' => $idempotencyKey,
            ]);

            if ($signedAmount > 0) {
                StoreCreditLot::query()->create([
                    'account_id' => $account->id, 'entry_id' => $entry->id,
                    'amount_minor' => $signedAmount, 'remaining_minor' => $signedAmount,
                    'expires_at' => $this->expiryForNewCredit(),
                ]);
            } else {
                $this->takeFromLots($account, -$signedAmount);
            }

            return $entry;
        });
    }

    /**
     * Phase B35: what lapsed by now, for one account, written as one `expired`
     * entry. Returns the amount (0 when nothing was due).
     */
    public function expireDue(StoreCreditAccount $account): int
    {
        return DB::transaction(function () use ($account) {
            $account = StoreCreditAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $due = StoreCreditLot::query()->where('account_id', $account->id)->where('remaining_minor', '>', 0)
                ->whereNotNull('expires_at')->where('expires_at', '<=', now())->orderBy('expires_at')->orderBy('id')->get();
            $amount = min((int) $due->sum('remaining_minor'), $account->balance_minor);
            if ($amount <= 0) {
                return 0;
            }

            $customer = Customer::query()->findOrFail($account->customer_id);
            // Lots that lapsed are the ones that expire first, so the debit takes exactly them.
            $this->debit($customer, $account->currency, $amount, StoreCreditEntryType::Expired, "expire:{$account->id}:{$due->max('id')}:{$amount}", null, 'Expired');

            return $amount;
        });
    }

    /**
     * What is still to expire next, if the customer has credit with an expiry.
     *
     * @return array{amount_minor: int, expires_at: string}|null
     */
    public function nextExpiry(Customer $customer, string $currency): ?array
    {
        $account = StoreCreditAccount::query()->where('customer_id', $customer->id)->where('currency', strtoupper($currency))->first();
        $lot = $account === null ? null : StoreCreditLot::query()->where('account_id', $account->id)->where('remaining_minor', '>', 0)
            ->whereNotNull('expires_at')->orderBy('expires_at')->first();
        if ($lot === null || $lot->expires_at === null) {
            return null;
        }
        $sameDay = (int) StoreCreditLot::query()->where('account_id', $account->id)->where('remaining_minor', '>', 0)
            ->whereDate('expires_at', $lot->expires_at->toDateString())->sum('remaining_minor');

        return ['amount_minor' => $sameDay, 'expires_at' => $lot->expires_at->toIso8601String()];
    }

    /** Spending takes from the credit that expires first; credit without expiry goes last. */
    private function takeFromLots(StoreCreditAccount $account, int $amount): void
    {
        $lots = StoreCreditLot::query()->where('account_id', $account->id)->where('remaining_minor', '>', 0)
            ->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->orderBy('id')->lockForUpdate()->get();
        foreach ($lots as $lot) {
            if ($amount <= 0) {
                break;
            }
            $take = min($amount, $lot->remaining_minor);
            $lot->update(['remaining_minor' => $lot->remaining_minor - $take]);
            $amount -= $take;
        }
    }

    private function expiryForNewCredit(): ?\Illuminate\Support\Carbon
    {
        $config = app(\App\Domain\Settings\Services\ConfigService::class);
        if (! (bool) $config->get('store_credit.expires')) {
            return null;
        }

        return now()->addDays(max(1, (int) $config->get('store_credit.expiry_days')));
    }

    /** The customer's account in this currency, created empty if needed, locked for the transaction. */
    private function lockedAccount(Customer $customer, string $currency): StoreCreditAccount
    {
        $currency = strtoupper($currency);
        StoreCreditAccount::query()->firstOrCreate(['customer_id' => $customer->id, 'currency' => $currency]);

        return StoreCreditAccount::query()->where('customer_id', $customer->id)->where('currency', $currency)->lockForUpdate()->firstOrFail();
    }
}
