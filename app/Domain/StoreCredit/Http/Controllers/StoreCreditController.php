<?php

declare(strict_types=1);

namespace App\Domain\StoreCredit\Http\Controllers;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Customers\Policies\CustomerPolicy;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Settings\Services\Currencies;
use App\Domain\StoreCredit\Exceptions\InsufficientStoreCreditException;
use App\Domain\StoreCredit\Models\StoreCreditAccount;
use App\Domain\StoreCredit\Models\StoreCreditEntry;
use App\Domain\StoreCredit\Models\StoreCreditEntryType;
use App\Domain\StoreCredit\Policies\StoreCreditPolicy;
use App\Domain\StoreCredit\Services\StoreCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 09 §52 (Phase B34): a customer's store credit — for staff (the
 * customer page) and for the customer themselves (their account).
 *
 * Staff see the balance and the ledger with `customers.view`. Giving or
 * taking credit by hand is creating or removing money: it needs
 * `store_credit.manage` (Administrator, Owner), a reason, and the
 * password again (step-up on the route); it is audited with the amount.
 * Everything else that moves credit (refunds, orders) happens in its own
 * workflow, never here.
 */
final class StoreCreditController
{
    public function __construct(private readonly StoreCreditService $credit) {}

    public function show(Request $request, Customer $customer): JsonResponse
    {
        abort_unless(app(CustomerPolicy::class)->view($request->user()), 403);

        return response()->json(['data' => [
            ...$this->summary($customer, staff: true),
            'can_adjust' => $this->mayAdjust($request->user()) && $customer->erased_at === null,
        ]]);
    }

    public function adjust(Request $request, Customer $customer, AuditLogger $audit): JsonResponse
    {
        abort_unless($this->mayAdjust($request->user()), 403);
        $data = $request->validate([
            // + gives credit, − takes it back; in the store's currency.
            'amount_minor' => ['required', 'integer', 'not_in:0', 'min:-100000000000', 'max:100000000000'],
            'note' => ['required', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
        ]);
        if ($customer->erased_at !== null) {
            return response()->json(['message' => 'This customer\'s personal data was erased. Their store credit has ended.', 'code' => 'erased'], 409);
        }

        $currency = (string) app(ConfigService::class)->get('store.default_currency');
        $amount = (int) $data['amount_minor'];
        $key = "adjust:{$customer->id}:".$data['idempotency_key'];

        try {
            $entry = $amount > 0
                ? $this->credit->credit($customer, $currency, $amount, StoreCreditEntryType::Adjustment, $key, null, $data['note'], $request->user()->id)
                : $this->credit->debit($customer, $currency, -$amount, StoreCreditEntryType::Adjustment, $key, null, $data['note'], $request->user()->id);
        } catch (InsufficientStoreCreditException $e) {
            return response()->json([
                'message' => 'The customer has only '.Currencies::readable($e->balanceMinor, $currency)." {$currency} of store credit.",
                'code' => 'insufficient_store_credit',
            ], 422);
        }

        if ($entry->wasRecentlyCreated) {
            $audit->record('store_credit.adjusted', ['amount_minor' => $amount, 'currency' => $currency, 'balance_after_minor' => $entry->balance_after_minor, 'reason' => $data['note']], $customer, actor: $request->user());
        }

        return response()->json(['data' => $this->summary($customer, staff: true)], 201);
    }

    /** The signed-in customer's own balance and history. */
    public function own(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return response()->json(['data' => $this->summary($customer, staff: false)]);
    }

    /** @return array<string, mixed> */
    private function summary(Customer $customer, bool $staff): array
    {
        $accounts = StoreCreditAccount::query()->where('customer_id', $customer->id)->orderBy('currency')->get();
        $currency = (string) app(ConfigService::class)->get('store.default_currency');

        return [
            // The balance in the store's currency is the one a checkout can use.
            'currency' => $currency,
            'balance_minor' => (int) ($accounts->firstWhere('currency', $currency)->balance_minor ?? 0),
            'other_balances' => $accounts->where('currency', '!=', $currency)->where('balance_minor', '>', 0)
                ->map(fn (StoreCreditAccount $account) => ['currency' => $account->currency, 'balance_minor' => $account->balance_minor])->values(),
            'entries' => StoreCreditEntry::query()->where('customer_id', $customer->id)->with($staff ? ['actor'] : [])->orderByDesc('id')->limit(50)->get()
                ->map(fn (StoreCreditEntry $entry) => [
                    'id' => $entry->public_id,
                    'type' => $entry->type->value,
                    'amount_minor' => $entry->amount_minor,
                    'balance_after_minor' => $entry->balance_after_minor,
                    'currency' => $entry->currency,
                    'note' => $entry->note,
                    'created_at' => $entry->created_at->toIso8601String(),
                    // Who of the staff did it is for staff only.
                    ...($staff ? ['by' => $entry->actor?->name] : []),
                ])->values(),
        ];
    }

    private function mayAdjust(User $user): bool
    {
        return app(StoreCreditPolicy::class)->manage($user);
    }
}
