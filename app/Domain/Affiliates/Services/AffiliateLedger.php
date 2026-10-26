<?php

declare(strict_types=1);

namespace App\Domain\Affiliates\Services;

use App\Domain\Affiliates\Models\Affiliate;
use App\Domain\Affiliates\Models\AffiliateAdjustment;
use App\Domain\Affiliates\Models\AffiliateCommission;
use App\Domain\Affiliates\Models\AffiliatePayout;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase B57 (Module 28 §21–24, §33–39, §42, §66, §69) — what affiliates
 * have earned and been paid.
 *
 * Follow-up (hourly, idempotent, from the orders as they are now — the
 * order and payment modules stay authoritative, §51, §54):
 * - a cancelled or failed order: its commission is rejected;
 * - refunds (payment refunds and store credit given for returns) take
 *   back the same share of the commission: before payout by raising
 *   reversed_minor, after payout by a negative adjustment (§23, §39);
 * - a pending commission is approved once the order is delivered (and
 *   paid, for cash on delivery) and the hold period has passed (§22, §24).
 *
 * Payouts gather an affiliate's approved commissions and adjustments; the
 * store pays outside the platform and records the reference. Paying
 * twice is impossible: one open payout per affiliate, rows locked (§118–119).
 */
final class AffiliateLedger
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{approved: int, reversed: int, rejected: int} */
    public function followUp(?Carbon $now = null): array
    {
        $now ??= now();
        $result = ['approved' => 0, 'reversed' => 0, 'rejected' => 0];
        AffiliateCommission::query()->withoutTenantScope()->whereIn('status', AffiliateCommission::LIVE)->orderBy('id')
            ->chunkById(200, function ($commissions) use ($now, &$result) {
                foreach ($commissions as $commission) {
                    $outcome = DB::transaction(fn () => $this->followOne($commission->id, $now));
                    if ($outcome !== null) {
                        $result[$outcome]++;
                    }
                }
            });

        return $result;
    }

    private function followOne(int $id, Carbon $now): ?string
    {
        $commission = AffiliateCommission::query()->withoutTenantScope()->lockForUpdate()->find($id);
        $order = $commission === null ? null : Order::query()->withoutGlobalScopes()->find($commission->order_id);
        if ($commission === null || $order === null || ! in_array($commission->status, AffiliateCommission::LIVE, true)) {
            return null;
        }
        $status = $order->status->value;

        $alreadyTaken = fn () => $commission->reversed_minor - (int) AffiliateAdjustment::query()->withoutTenantScope()->where('affiliate_commission_id', $commission->id)->where('amount_minor', '<', 0)->whereNull('created_by')->sum('amount_minor');
        if (in_array($status, ['cancelled', 'failed'], true)) {
            if ($commission->status === 'paid' || $commission->affiliate_payout_id !== null) {
                // Already paid or gathered into a payout: the rest is taken back from the next payout.
                return $this->takeBack($commission, $commission->amount_minor - $alreadyTaken(), 'Order '.$order->order_number.' was cancelled.') ? 'reversed' : null;
            }
            $commission->update(['status' => 'rejected', 'decision_note' => 'The order was cancelled.']);

            return 'rejected';
        }

        // Refunds take back the same share of the commission (§23).
        $refunded = $this->refunded($order);
        $grand = max(1, (int) $order->grand_total_minor);
        $shouldReverse = $commission->amount_minor - intdiv($commission->amount_minor * max(0, $grand - min($refunded, $grand)), $grand);
        $reversedNow = false;
        $taken = $alreadyTaken();
        if ($shouldReverse > $taken) {
            $reversedNow = $this->takeBack($commission, $shouldReverse - $taken, "Refund on order {$order->order_number}.");
            $commission->refresh();
            if ($commission->net() === 0 && $commission->status !== 'paid') {
                $commission->update(['status' => 'reversed']);

                return 'reversed';
            }
        }

        if ($commission->status === 'pending') {
            $since = $this->qualifiedSince($order, (array) $commission->snapshot);
            if ($since !== null) {
                $holdUntil = $since->copy()->addDays((int) ($commission->snapshot['hold_days'] ?? 0));
                if ($commission->hold_until === null || ! $commission->hold_until->equalTo($holdUntil)) {
                    $commission->update(['hold_until' => $holdUntil]);
                }
                if ($holdUntil->lessThanOrEqualTo($now)) {
                    $commission->update(['status' => 'approved', 'approved_at' => $now]);

                    return 'approved';
                }
            }
        }

        return $reversedNow ? 'reversed' : null;
    }

    /**
     * Take back an amount (§39): on the commission while nothing was paid or
     * gathered into a payout for it; otherwise by a negative adjustment that
     * the next payout settles — a payout's amount never changes after the fact.
     */
    private function takeBack(AffiliateCommission $commission, int $amount, string $reason): bool
    {
        if ($amount <= 0) {
            return false;
        }
        if ($commission->status === 'paid' || $commission->affiliate_payout_id !== null) {
            AffiliateAdjustment::query()->create([
                'store_id' => $commission->store_id, 'affiliate_id' => $commission->affiliate_id, 'affiliate_commission_id' => $commission->id,
                'amount_minor' => -$amount, 'currency' => $commission->currency, 'reason' => $reason,
            ]);

            return true;
        }
        $commission->update(['reversed_minor' => min($commission->amount_minor, $commission->reversed_minor + $amount)]);

        return true;
    }

    /** Money given back for the order: payment refunds and store credit for returns. */
    private function refunded(Order $order): int
    {
        $payments = (int) DB::table('payment_transactions')
            ->whereIn('payment_id', DB::table('payments')->where('order_id', $order->id)->select('id'))
            ->whereIn('type', ['refund', 'partial_refund'])->where('status', 'succeeded')->sum('amount_minor');
        $credit = (int) DB::table('return_requests')->where('order_id', $order->id)->sum('refunded_credit_minor');

        return $payments + $credit;
    }

    /**
     * When the order met the program's conditions, or null while it has not (§24).
     *
     * @param array<string, mixed> $snapshot
     */
    private function qualifiedSince(Order $order, array $snapshot): ?Carbon
    {
        // A partial refund leaves the order paid for the rest (its share is taken back above).
        if (($snapshot['require_payment'] ?? true) && ! in_array($order->payment_status->value, ['paid', 'partially_refunded'], true)) {
            return null;
        }
        if (! ($snapshot['require_delivery'] ?? true)) {
            return Carbon::parse($order->created_at);
        }

        // Delivered by its shipments, or moved to delivered by staff (before: only the second counted).
        return \App\Domain\Orders\Support\OrderDelivery::deliveredAt($order);
    }

    /** §42: the store decides on a commission held for review. */
    public function decide(User $by, AffiliateCommission $commission, string $decision, ?string $note): void
    {
        $this->refuseIf($commission->status !== 'review', 'not_in_review', 'Only a commission held for review can be decided.');
        $commission->update(['status' => $decision === 'release' ? 'pending' : 'rejected', 'decided_by' => $by->id, 'decision_note' => $note]);
        $this->audit->record('affiliates.commission.'.($decision === 'release' ? 'released' : 'rejected'), ['note' => $note, 'flags' => $commission->flags], $commission, actor: $by);
    }

    /** §69: a correction to an affiliate's balance, with its reason. */
    public function adjust(User $by, Affiliate $affiliate, int $amount, string $currency, string $reason): AffiliateAdjustment
    {
        $this->refuseIf($amount === 0, 'amount_zero', 'Enter an amount other than zero.');
        $adjustment = AffiliateAdjustment::query()->create([
            'store_id' => $affiliate->store_id, 'affiliate_id' => $affiliate->id, 'amount_minor' => $amount, 'currency' => $currency, 'reason' => $reason, 'created_by' => $by->id,
        ]);
        $this->audit->record('affiliates.adjustment.created', ['amount_minor' => $amount, 'reason' => $reason, 'balance_after' => $this->balances($affiliate->id)[$affiliate->id]['payable_minor'] ?? 0], $affiliate, actor: $by);

        return $adjustment;
    }

    /**
     * Per affiliate: what is earned but not yet confirmed, what can be paid, and what was paid.
     *
     * @return array<int, array{pending_minor: int, payable_minor: int, paid_minor: int, in_payout_minor: int}>
     */
    public function balances(?int $affiliateId = null): array
    {
        $out = [];
        $add = function (int $id, string $key, int $amount) use (&$out) {
            $out[$id] ??= ['pending_minor' => 0, 'payable_minor' => 0, 'paid_minor' => 0, 'in_payout_minor' => 0];
            $out[$id][$key] += $amount;
        };
        $pendingPayouts = AffiliatePayout::query()->where('status', 'pending')->pluck('id')->all();
        AffiliateCommission::query()->whereIn('status', AffiliateCommission::LIVE)->when($affiliateId, fn ($q) => $q->where('affiliate_id', $affiliateId))
            ->get(['affiliate_id', 'status', 'amount_minor', 'reversed_minor', 'affiliate_payout_id'])
            ->each(function (AffiliateCommission $c) use ($add, $pendingPayouts) {
                $net = $c->net();
                match (true) {
                    in_array($c->status, ['pending', 'review'], true) => $add($c->affiliate_id, 'pending_minor', $net),
                    $c->status === 'approved' && in_array($c->affiliate_payout_id, $pendingPayouts, true) => $add($c->affiliate_id, 'in_payout_minor', $net),
                    $c->status === 'approved' => $add($c->affiliate_id, 'payable_minor', $net),
                    default => $add($c->affiliate_id, 'paid_minor', $net),
                };
            });
        AffiliateAdjustment::query()->when($affiliateId, fn ($q) => $q->where('affiliate_id', $affiliateId))->get(['affiliate_id', 'amount_minor', 'affiliate_payout_id'])
            ->each(fn (AffiliateAdjustment $a) => $add($a->affiliate_id, $a->affiliate_payout_id === null ? 'payable_minor' : (in_array($a->affiliate_payout_id, $pendingPayouts, true) ? 'in_payout_minor' : 'paid_minor'), (int) $a->amount_minor));

        return $out;
    }

    /** Gather what an affiliate can be paid into one payout (§33, §35, §37). */
    public function createPayout(User $by, Affiliate $affiliate, int $thresholdMinor, ?string $note): AffiliatePayout
    {
        $this->refuseIf($affiliate->status !== 'active', 'affiliate_not_active', 'Payouts are only made to active affiliates.');
        $this->refuseIf($affiliate->payout_method === null, 'no_payout_details', 'The affiliate has not given payout details yet.');

        return DB::transaction(function () use ($by, $affiliate, $thresholdMinor, $note) {
            Affiliate::query()->whereKey($affiliate->id)->lockForUpdate()->first();
            $this->refuseIf(AffiliatePayout::query()->where('affiliate_id', $affiliate->id)->where('status', 'pending')->exists(), 'payout_open', 'This affiliate already has a payout waiting to be paid.');
            $commissions = AffiliateCommission::query()->where('affiliate_id', $affiliate->id)->where('status', 'approved')->whereNull('affiliate_payout_id')->lockForUpdate()->get();
            $adjustments = AffiliateAdjustment::query()->where('affiliate_id', $affiliate->id)->whereNull('affiliate_payout_id')->lockForUpdate()->get();
            $amount = (int) $commissions->sum(fn (AffiliateCommission $c) => $c->net()) + (int) $adjustments->sum('amount_minor');
            $this->refuseIf($amount <= 0, 'nothing_payable', 'There is nothing to pay this affiliate yet.');
            $this->refuseIf($amount < $thresholdMinor, 'below_threshold', 'The amount is below the program’s payout minimum.');
            $currency = (string) ($commissions->first()->currency ?? $adjustments->first()->currency);

            $payout = AffiliatePayout::query()->create([
                'store_id' => $affiliate->store_id, 'affiliate_id' => $affiliate->id, 'status' => 'pending', 'amount_minor' => $amount,
                'currency' => $currency, 'method' => $affiliate->payout_method, 'note' => $note, 'created_by' => $by->id,
            ]);
            AffiliateCommission::query()->whereIn('id', $commissions->pluck('id'))->update(['affiliate_payout_id' => $payout->id]);
            AffiliateAdjustment::query()->whereIn('id', $adjustments->pluck('id'))->update(['affiliate_payout_id' => $payout->id]);
            $this->audit->record('affiliates.payout.created', ['amount_minor' => $amount, 'commissions' => $commissions->count(), 'adjustments' => $adjustments->count()], $payout, actor: $by);

            return $payout;
        });
    }

    /** The store paid it (outside the platform) and records the reference (§38). */
    public function markPaid(User $by, AffiliatePayout $payout, string $reference): void
    {
        DB::transaction(function () use ($by, $payout, $reference) {
            $locked = AffiliatePayout::query()->lockForUpdate()->findOrFail($payout->id);
            $this->refuseIf($locked->status !== 'pending', 'payout_not_pending', 'This payout is not waiting to be paid.');
            $locked->update(['status' => 'paid', 'reference' => $reference, 'paid_by' => $by->id, 'paid_at' => now()]);
            AffiliateCommission::query()->where('affiliate_payout_id', $locked->id)->where('status', 'approved')->update(['status' => 'paid']);
            $this->audit->record('affiliates.payout.paid', ['amount_minor' => $locked->amount_minor, 'reference' => $reference], $locked, actor: $by);
        });
    }

    public function cancelPayout(User $by, AffiliatePayout $payout): void
    {
        DB::transaction(function () use ($by, $payout) {
            $locked = AffiliatePayout::query()->lockForUpdate()->findOrFail($payout->id);
            $this->refuseIf($locked->status !== 'pending', 'payout_not_pending', 'Only a payout not yet paid can be cancelled.');
            $locked->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            AffiliateCommission::query()->where('affiliate_payout_id', $locked->id)->update(['affiliate_payout_id' => null]);
            AffiliateAdjustment::query()->where('affiliate_payout_id', $locked->id)->update(['affiliate_payout_id' => null]);
            $this->audit->record('affiliates.payout.cancelled', ['amount_minor' => $locked->amount_minor], $locked, actor: $by);
        });
    }

    private function refuseIf(bool $condition, string $code, string $message): void
    {
        if ($condition) {
            throw new AffiliateRefused($code, $message);
        }
    }
}
