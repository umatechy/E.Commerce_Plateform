<?php

declare(strict_types=1);

namespace App\Domain\Resellers\Services;

use App\Domain\Billing\Models\BillingReason;
use App\Domain\Billing\Models\CreditNote;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceStatus;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Resellers\Models\Reseller;
use App\Domain\Resellers\Models\ResellerAdjustment;
use App\Domain\Resellers\Models\ResellerCommission;
use App\Domain\Resellers\Models\ResellerPayout;
use App\Domain\Resellers\Models\ResellerStore;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase B58 (Module 28 §21–25, §33–39, §52, §66, §69) — what resellers have
 * earned and been paid. Billing (Module 29) stays the authority: commissions
 * are read from paid Umar Techy invoices, never from what anyone says.
 *
 * Follow-up (hourly, idempotent):
 * - every paid invoice of a store with a reseller, issued while the store
 *   was theirs and before any termination, earns one commission (unique per
 *   invoice), from the terms in the attribution: the first one at the
 *   initial rate, later ones (renewals, upgrades) at the recurring rate
 *   within the recurring period (§25);
 * - credit notes on that invoice take back the same share — before payout
 *   on the commission, after payout by a negative adjustment (§23, §39);
 * - a pending commission is approved when its hold period ends.
 *
 * Payouts are per currency: they gather approved commissions and
 * adjustments of one currency; Umar Techy pays outside the platform and
 * records the reference. One open payout per reseller (§118–119).
 */
final class ResellerLedger
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{created: int, approved: int, reversed: int} */
    public function followUp(?Carbon $now = null): array
    {
        $now ??= now();
        $result = ['created' => 0, 'approved' => 0, 'reversed' => 0];

        ResellerStore::query()->with('reseller')->orderBy('id')->chunkById(200, function ($attributions) use (&$result) {
            foreach ($attributions as $attribution) {
                $result['created'] += $this->earn($attribution);
            }
        });

        ResellerCommission::query()->whereIn('status', ResellerCommission::LIVE)->orderBy('id')->chunkById(200, function ($commissions) use ($now, &$result) {
            foreach ($commissions as $commission) {
                $outcome = DB::transaction(fn () => $this->followOne($commission->id, $now));
                if ($outcome !== null) {
                    $result[$outcome]++;
                }
            }
        });

        return $result;
    }

    /** New commissions for the paid invoices of one attributed store. */
    private function earn(ResellerStore $attribution): int
    {
        $terms = $attribution->terms;
        $reseller = $attribution->reseller;
        $invoices = Invoice::query()->withoutTenantScope()
            ->where('store_id', $attribution->store_id)
            ->where('status', InvoiceStatus::Paid->value)
            ->where('subtotal_minor', '>', 0)
            ->where('issued_at', '>=', $attribution->created_at)
            ->when($attribution->ended_at !== null, fn ($q) => $q->where('issued_at', '<', $attribution->ended_at))
            ->when($reseller->terminated_at !== null, fn ($q) => $q->where('issued_at', '<', $reseller->terminated_at))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('reseller_commissions')->whereColumn('reseller_commissions.invoice_id', 'invoices.id'))
            ->orderBy('issued_at')->orderBy('id')->get();

        $created = 0;
        foreach ($invoices as $invoice) {
            $hasInitial = ResellerCommission::query()->where('reseller_store_id', $attribution->id)->where('kind', 'initial')->exists();
            $kind = $invoice->billing_reason === BillingReason::Proration ? 'upgrade' : ($hasInitial ? 'renewal' : 'initial');
            if ($kind !== 'initial' && ! $this->withinRecurring($attribution, $invoice)) {
                continue;
            }
            $rate = (int) ($kind === 'initial' ? $terms['initial_commission_bp'] : $terms['recurring_commission_bp']);
            $amount = intdiv((int) $invoice->subtotal_minor * $rate, 10000);
            if ($amount <= 0) {
                continue;
            }
            try {
                $commission = ResellerCommission::query()->create([
                    'reseller_id' => $attribution->reseller_id, 'reseller_store_id' => $attribution->id, 'store_id' => $attribution->store_id,
                    'invoice_id' => $invoice->id, 'kind' => $kind, 'status' => 'pending', 'base_minor' => (int) $invoice->subtotal_minor,
                    'rate_bp' => $rate, 'amount_minor' => $amount, 'currency' => $invoice->currency,
                    'hold_until' => Carbon::parse($invoice->paid_at ?? $invoice->issued_at)->addDays($terms['hold_days']),
                ]);
            } catch (UniqueConstraintViolationException) {
                continue; // another run made it first
            }
            $this->audit->record('resellers.commission.created', ['invoice' => $invoice->number, 'kind' => $kind, 'amount_minor' => $amount, 'currency' => $invoice->currency], $commission, platform: true);
            $created++;
        }

        return $created;
    }

    /** §25: later invoices count while the recurring period (from the attribution) lasts; none when it is 0. */
    private function withinRecurring(ResellerStore $attribution, Invoice $invoice): bool
    {
        $months = $attribution->terms['recurring_months'] ?? null;
        if ($months === null) {
            return true;
        }

        return (int) $months > 0 && Carbon::parse($invoice->period_start)->lessThan($attribution->created_at->copy()->addMonths((int) $months));
    }

    private function followOne(int $id, Carbon $now): ?string
    {
        $commission = ResellerCommission::query()->lockForUpdate()->find($id);
        if ($commission === null || ! in_array($commission->status, ResellerCommission::LIVE, true)) {
            return null;
        }

        // Credit notes take back the same share of the commission (§23, §25 "refund").
        $credited = (int) CreditNote::query()->withoutTenantScope()->where('invoice_id', $commission->invoice_id)->where('status', CreditNote::ISSUED)->sum('subtotal_minor');
        $base = max(1, $commission->base_minor);
        $shouldReverse = $commission->amount_minor - intdiv($commission->amount_minor * max(0, $base - min($credited, $base)), $base);
        $taken = $commission->reversed_minor - (int) ResellerAdjustment::query()->where('reseller_commission_id', $commission->id)->where('amount_minor', '<', 0)->whereNull('created_by')->sum('amount_minor');
        if ($shouldReverse > $taken) {
            $invoice = Invoice::query()->withoutTenantScope()->find($commission->invoice_id);
            $this->takeBack($commission, $shouldReverse - $taken, 'Credit note on invoice '.($invoice->number ?? '').'.');
            $commission->refresh();
            if ($commission->net() === 0 && $commission->status !== 'paid') {
                $commission->update(['status' => 'reversed']);
            }

            return 'reversed';
        }

        if ($commission->status === 'pending' && ($commission->hold_until === null || $commission->hold_until->lessThanOrEqualTo($now))) {
            $commission->update(['status' => 'approved']);

            return 'approved';
        }

        return null;
    }

    /** Before payout on the commission; after it, by an adjustment the next payout settles (§39). */
    private function takeBack(ResellerCommission $commission, int $amount, string $reason): void
    {
        if ($commission->status === 'paid' || $commission->reseller_payout_id !== null) {
            ResellerAdjustment::query()->create([
                'reseller_id' => $commission->reseller_id, 'reseller_commission_id' => $commission->id,
                'amount_minor' => -$amount, 'currency' => $commission->currency, 'reason' => $reason,
            ]);

            return;
        }
        $commission->update(['reversed_minor' => min($commission->amount_minor, $commission->reversed_minor + $amount)]);
    }

    /** Umar Techy refuses a commission it should not pay (§42 fraud review), with a note. */
    public function reject(User $by, ResellerCommission $commission, string $note): void
    {
        DB::transaction(function () use ($by, $commission, $note) {
            $locked = ResellerCommission::query()->lockForUpdate()->findOrFail($commission->id);
            $this->refuseIf(! in_array($locked->status, ['pending', 'approved'], true) || $locked->reseller_payout_id !== null, 'not_rejectable', 'Only a commission not yet in a payout can be rejected.');
            $locked->update(['status' => 'rejected', 'decision_note' => $note]);
            $this->audit->record('resellers.commission.rejected', ['note' => $note, 'amount_minor' => $locked->amount_minor], $locked, actor: $by, platform: true);
        });
    }

    /** §69: a correction to a reseller's balance, with its reason. */
    public function adjust(User $by, Reseller $reseller, int $amount, string $currency, string $reason): ResellerAdjustment
    {
        $this->refuseIf($amount === 0, 'amount_zero', 'Enter an amount other than zero.');
        $adjustment = ResellerAdjustment::query()->create([
            'reseller_id' => $reseller->id, 'amount_minor' => $amount, 'currency' => strtoupper($currency), 'reason' => $reason, 'created_by' => $by->id,
        ]);
        $this->audit->record('resellers.adjustment.created', ['amount_minor' => $amount, 'currency' => $currency, 'reason' => $reason], $reseller, actor: $by, platform: true);

        return $adjustment;
    }

    /**
     * Per reseller and currency: earned but not yet confirmed, payable, in a payout, paid.
     *
     * @return array<int, array<string, array{pending_minor: int, payable_minor: int, in_payout_minor: int, paid_minor: int}>>
     */
    public function balances(?int $resellerId = null): array
    {
        $out = [];
        $add = function (int $id, string $currency, string $key, int $amount) use (&$out) {
            $out[$id][$currency] ??= ['pending_minor' => 0, 'payable_minor' => 0, 'in_payout_minor' => 0, 'paid_minor' => 0];
            $out[$id][$currency][$key] += $amount;
        };
        $open = ResellerPayout::query()->where('status', 'pending')->pluck('id')->all();
        ResellerCommission::query()->whereIn('status', ResellerCommission::LIVE)->when($resellerId, fn ($q) => $q->where('reseller_id', $resellerId))
            ->get(['reseller_id', 'status', 'amount_minor', 'reversed_minor', 'currency', 'reseller_payout_id'])
            ->each(function (ResellerCommission $c) use ($add, $open) {
                $key = match (true) {
                    $c->status === 'pending' => 'pending_minor',
                    $c->status === 'approved' && in_array($c->reseller_payout_id, $open, true) => 'in_payout_minor',
                    $c->status === 'approved' => 'payable_minor',
                    default => 'paid_minor',
                };
                $add($c->reseller_id, $c->currency, $key, $c->net());
            });
        ResellerAdjustment::query()->when($resellerId, fn ($q) => $q->where('reseller_id', $resellerId))->get(['reseller_id', 'amount_minor', 'currency', 'reseller_payout_id'])
            ->each(fn (ResellerAdjustment $a) => $add($a->reseller_id, $a->currency, $a->reseller_payout_id === null ? 'payable_minor' : (in_array($a->reseller_payout_id, $open, true) ? 'in_payout_minor' : 'paid_minor'), $a->amount_minor));

        return $out;
    }

    /** Gather what a reseller can be paid in one currency (§33, §35, §37). */
    public function createPayout(User $by, Reseller $reseller, string $currency, ?string $note): ResellerPayout
    {
        $currency = strtoupper($currency);
        $this->refuseIf($reseller->status === 'rejected' || $reseller->status === 'invited' || $reseller->status === 'pending', 'reseller_not_joined', 'This reseller has nothing to be paid.');
        $this->refuseIf($reseller->payout_method === null, 'no_payout_details', 'The reseller has not given payout details yet.');

        return DB::transaction(function () use ($by, $reseller, $currency, $note) {
            Reseller::query()->whereKey($reseller->id)->lockForUpdate()->first();
            $this->refuseIf(ResellerPayout::query()->where('reseller_id', $reseller->id)->where('status', 'pending')->exists(), 'payout_open', 'This reseller already has a payout waiting to be paid.');
            $commissions = ResellerCommission::query()->where('reseller_id', $reseller->id)->where('status', 'approved')->where('currency', $currency)->whereNull('reseller_payout_id')->lockForUpdate()->get();
            $adjustments = ResellerAdjustment::query()->where('reseller_id', $reseller->id)->where('currency', $currency)->whereNull('reseller_payout_id')->lockForUpdate()->get();
            $amount = (int) $commissions->sum(fn (ResellerCommission $c) => $c->net()) + (int) $adjustments->sum('amount_minor');
            $this->refuseIf($amount <= 0, 'nothing_payable', 'There is nothing to pay this reseller in '.$currency.'.');
            $this->refuseIf($amount < (int) ($reseller->tier->payout_threshold_minor ?? 0), 'below_threshold', 'The amount is below the tier’s payout minimum.');

            $payout = ResellerPayout::query()->create([
                'reseller_id' => $reseller->id, 'status' => 'pending', 'amount_minor' => $amount, 'currency' => $currency,
                'method' => $reseller->payout_method, 'note' => $note, 'created_by' => $by->id,
            ]);
            ResellerCommission::query()->whereIn('id', $commissions->pluck('id'))->update(['reseller_payout_id' => $payout->id]);
            ResellerAdjustment::query()->whereIn('id', $adjustments->pluck('id'))->update(['reseller_payout_id' => $payout->id]);
            $this->audit->record('resellers.payout.created', ['amount_minor' => $amount, 'currency' => $currency, 'commissions' => $commissions->count(), 'adjustments' => $adjustments->count()], $payout, actor: $by, platform: true);

            return $payout;
        });
    }

    /** Umar Techy paid it (outside the platform) and records the reference (§38). */
    public function markPaid(User $by, ResellerPayout $payout, string $reference): void
    {
        DB::transaction(function () use ($by, $payout, $reference) {
            $locked = ResellerPayout::query()->lockForUpdate()->findOrFail($payout->id);
            $this->refuseIf($locked->status !== 'pending', 'payout_not_pending', 'This payout is not waiting to be paid.');
            $locked->update(['status' => 'paid', 'reference' => $reference, 'paid_by' => $by->id, 'paid_at' => now()]);
            ResellerCommission::query()->where('reseller_payout_id', $locked->id)->where('status', 'approved')->update(['status' => 'paid']);
            $this->audit->record('resellers.payout.paid', ['amount_minor' => $locked->amount_minor, 'currency' => $locked->currency, 'reference' => $reference], $locked, actor: $by, platform: true);
        });
    }

    public function cancelPayout(User $by, ResellerPayout $payout): void
    {
        DB::transaction(function () use ($by, $payout) {
            $locked = ResellerPayout::query()->lockForUpdate()->findOrFail($payout->id);
            $this->refuseIf($locked->status !== 'pending', 'payout_not_pending', 'Only a payout not yet paid can be cancelled.');
            $locked->update(['status' => 'cancelled']);
            ResellerCommission::query()->where('reseller_payout_id', $locked->id)->update(['reseller_payout_id' => null]);
            ResellerAdjustment::query()->where('reseller_payout_id', $locked->id)->update(['reseller_payout_id' => null]);
            $this->audit->record('resellers.payout.cancelled', ['amount_minor' => $locked->amount_minor, 'currency' => $locked->currency], $locked, actor: $by, platform: true);
        });
    }

    private function refuseIf(bool $condition, string $code, string $message): void
    {
        if ($condition) {
            throw new ResellerRefused($code, $message);
        }
    }
}
