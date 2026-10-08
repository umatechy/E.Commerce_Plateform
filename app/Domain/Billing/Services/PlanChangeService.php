<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Exceptions\BillingActionRefusedException;
use App\Domain\Billing\Models\BillingReason;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceStatus;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Packages\Services\SubscriptionLifecycleService;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase B47 — Module 29 §47–49, Module 04 §22: a store changes its package.
 *
 * - **Trial**: the new package at once; nothing to charge (the trial is free).
 * - **Upgrade** (a higher price in the same interval): at once by default
 *   (platform setting billing.upgrade_timing = immediate_prorated), with an
 *   invoice for the rest of the period: the new plan's remaining value less
 *   the unused value of the old plan (both by the seconds left of the
 *   period). If the next period is already paid, its difference is charged
 *   too. Or, with next_period, like a downgrade.
 * - **Downgrade** (a lower price): at the end of the period already paid
 *   for — the store keeps what it paid for; the next invoice is for the new
 *   package and the engine switches the package when that period starts.
 *   Nothing is deleted: Module 04 decides what over-limit data may do.
 * - **Same price**: at once, nothing to charge.
 *
 * An invoice already issued for the next period is never changed (§94): a
 * downgrade then starts after that period, and an immediate upgrade also
 * charges the next period's difference. Refused: an unpaid invoice for the
 * current period (pay it first), a package without a price in the
 * subscription's interval and currency.
 *
 * Integer maths only; one transaction with the subscription locked (the
 * same order as the billing run).
 */
final class PlanChangeService
{
    public function __construct(
        private readonly InvoiceLedger $ledger,
        private readonly SubscriptionLifecycleService $lifecycle,
        private readonly SubscriptionBillingEngine $engine,
        private readonly ConfigService $config,
        private readonly AuditLogger $audit,
        private readonly RecordsOutboxEvents $outbox,
    ) {}

    /** @return array<string, mixed> what would happen — nothing is changed */
    public function preview(Subscription $subscription, Package $target): array
    {
        $current = $subscription->package()->firstOrFail();
        $interval = $this->ledger->intervalOf($subscription);
        $currentPrice = $this->ledger->priceFor($subscription, null, $current->id);
        $targetPrice = $this->ledger->priceFor($subscription, null, $target->id);
        $store = Store::query()->withTrashed()->findOrFail($subscription->store_id);

        $base = [
            'from' => ['code' => $current->code, 'name' => $current->name, 'price_minor' => $currentPrice?->amount_minor],
            'to' => ['code' => $target->code, 'name' => $target->name, 'price_minor' => $targetPrice?->amount_minor],
            'interval' => $interval->value,
            'currency' => ($targetPrice ?? $currentPrice)?->currency,
            'features_lost' => $this->featureDiff($current, $target),
            'features_gained' => $this->featureDiff($target, $current),
            'limits_exceeded' => $this->lifecycle->detectOverLimitUsage($store, $target),
            'scheduled' => $subscription->scheduled_package_id !== null ? Package::query()->whereKey($subscription->scheduled_package_id)->value('code') : null,
            'proration' => null,
            'blocked' => null,
        ];

        if ($target->id === $current->id) {
            return [...$base, 'direction' => 'same', 'timing' => null, 'blocked' => 'same_package'];
        }
        if (! $target->is_active || $targetPrice === null) {
            return [...$base, 'direction' => null, 'timing' => null, 'blocked' => 'no_price'];
        }

        $direction = $currentPrice === null || $targetPrice->amount_minor > $currentPrice->amount_minor ? 'upgrade'
            : ($targetPrice->amount_minor < $currentPrice->amount_minor ? 'downgrade' : 'lateral');

        if ($subscription->status === SubscriptionStatus::Trialing) {
            return [...$base, 'direction' => $direction, 'timing' => 'now', 'effective_at' => now()->toIso8601String(), 'reason' => 'trial'];
        }
        // An invoice already issued for the next period is never changed (Module 29 §94): it bills the
        // package it was issued for, and a change is measured around it.
        $next = $this->ledger->nextPeriodInvoice($subscription);

        $immediateUpgrade = $direction === 'lateral' || ($direction === 'upgrade' && $this->config->get('billing.upgrade_timing') !== 'next_period');
        if (! $immediateUpgrade) {
            // At the end of what is already billed: this period, or the next one when its invoice is issued.
            $effective = $next !== null ? $next->period_end : $subscription->current_period_ends_at;

            return [...$base, 'direction' => $direction, 'timing' => 'period_end', 'effective_at' => $effective?->toIso8601String()];
        }
        // Pay what is due first: the current period's invoice, or any overdue one.
        $currentInvoice = $this->currentPeriodInvoice($subscription);
        $overdue = Invoice::query()->withoutTenantScope()->where('subscription_id', $subscription->id)->where('status', InvoiceStatus::Open->value)->where('due_at', '<=', now())->exists();
        if ($overdue || ($currentInvoice !== null && $currentInvoice->status === InvoiceStatus::Open)) {
            return [...$base, 'direction' => $direction, 'timing' => null, 'blocked' => 'pay_open_invoice'];
        }

        return [...$base, 'direction' => $direction, 'timing' => 'now', 'effective_at' => now()->toIso8601String(),
            'proration' => $this->proration($subscription, (int) $currentPrice?->amount_minor, $targetPrice->amount_minor, $currentInvoice, $next !== null && $next->status !== InvoiceStatus::Void ? $next : null)];
    }

    /**
     * @param 'owner'|'staff' $by
     *
     * @throws BillingActionRefusedException
     */
    public function apply(Subscription $subscription, Package $target, User $actor, string $by): array
    {
        return DB::transaction(function () use ($subscription, $target, $actor, $by) {
            $subscription = Subscription::query()->withoutTenantScope()->lockForUpdate()->findOrFail($subscription->id);
            $preview = $this->preview($subscription, $target);
            if ($preview['blocked'] !== null) {
                throw new BillingActionRefusedException($preview['blocked'], match ($preview['blocked']) {
                    'same_package' => 'The store is already on this package.',
                    'no_price' => 'This package has no price for your billing period yet.',
                    'pay_open_invoice' => 'Pay the open invoice for this period first, then change the plan.',
                    default => 'This plan change is not possible now.',
                }, 422);
            }
            $from = $subscription->package()->firstOrFail();
            $store = Store::query()->withTrashed()->findOrFail($subscription->store_id);
            $invoice = null;

            if ($preview['timing'] === 'now') {
                $this->lifecycle->changePackage($store, $target, $by === 'owner' ? 'store owner' : 'platform staff', 'plan_change');
                $subscription->forceFill(['package_id' => $target->id, 'scheduled_package_id' => null])->save();
                $p = $preview['proration'];
                if ($p !== null && $p['charge_minor'] - $p['credit_minor'] > 0) {
                    $invoice = $this->ledger->issueProration($subscription, $from, $target, $p['charge_minor'], $p['credit_minor'],
                        CarbonImmutable::parse($p['from']), CarbonImmutable::parse($p['until']));
                }
            } else {
                $subscription->forceFill(['scheduled_package_id' => $target->id])->save();
            }
            $this->engine->process($subscription); // issues the renewal again when it is due

            $this->audit->record('billing.plan_changed', [
                'from' => $from->code, 'to' => $target->code, 'timing' => $preview['timing'], 'by' => $by,
                'proration_invoice' => $invoice?->number, 'net_minor' => $preview['proration']['net_minor'] ?? 0,
            ], $subscription, $subscription->store_id, $actor);
            $this->outbox->recordEventFor($subscription->store_id, 'billing.plan_changed', ['subscription_id' => $subscription->id, 'from' => $from->code, 'to' => $target->code, 'timing' => $preview['timing']], "subscription:{$subscription->id}:plan:".now()->getTimestampMs());

            return ['timing' => $preview['timing'], 'effective_at' => $preview['effective_at'] ?? null, 'invoice' => $invoice];
        });
    }

    /** Keeps the current package after all: the scheduled change is dropped and the renewal billed for it is issued again. */
    public function cancelScheduled(Subscription $subscription, User $actor): void
    {
        DB::transaction(function () use ($subscription, $actor) {
            $subscription = Subscription::query()->withoutTenantScope()->lockForUpdate()->findOrFail($subscription->id);
            if ($subscription->scheduled_package_id === null) {
                throw new BillingActionRefusedException('nothing_scheduled', 'No plan change is scheduled.', 422);
            }
            $next = $this->ledger->nextPeriodInvoice($subscription);
            if ($next !== null && $next->package_id === $subscription->scheduled_package_id) {
                throw new BillingActionRefusedException('renewal_issued', 'The next period is already invoiced for the new package; change the plan again after it starts.', 422);
            }
            $subscription->forceFill(['scheduled_package_id' => null])->save();
            $this->engine->process($subscription);
            $this->audit->record('billing.plan_change_cancelled', [], $subscription, $subscription->store_id, $actor);
        });
    }

    /**
     * The rest of the period (and the next one, when already invoiced): what the
     * new plan costs for it, and what is left of the old one.
     *
     * @return array{from: string, until: string, remaining_seconds: int, period_seconds: int, charge_minor: int, credit_minor: int, net_minor: int, tax_minor: int, total_minor: int}
     */
    private function proration(Subscription $subscription, int $oldPrice, int $newPrice, ?Invoice $currentInvoice, ?Invoice $issuedNext): array
    {
        $now = CarbonImmutable::now();
        $start = CarbonImmutable::instance($subscription->current_period_started_at ?? $subscription->current_period_ends_at);
        $end = CarbonImmutable::instance($subscription->current_period_ends_at);
        $period = max(1, $end->getTimestamp() - $start->getTimestamp());
        $remaining = max(0, min($period, $end->getTimestamp() - $now->getTimestamp()));
        $part = fn (int $price) => intdiv(2 * $price * $remaining + $period, 2 * $period); // half up

        $charge = $part($newPrice);
        // What was paid for: the current period's invoice settled with money (a waived one gives nothing back).
        $paid = $currentInvoice !== null && $currentInvoice->status === InvoiceStatus::Paid;
        $credit = $paid ? $part($oldPrice) : 0;
        $until = $end;
        if ($issuedNext !== null) {
            $charge += $newPrice;
            $credit += $oldPrice;
            $until = CarbonImmutable::instance($issuedNext->period_end);
        }
        $net = max(0, $charge - $credit);
        $tax = $this->ledger->taxOn($net, $this->ledger->taxRateBps());

        return [
            'from' => $now->toIso8601String(), 'until' => $until->toIso8601String(), 'remaining_seconds' => $remaining, 'period_seconds' => $period,
            'charge_minor' => $charge, 'credit_minor' => $credit, 'net_minor' => $net, 'tax_minor' => $tax, 'total_minor' => $net + $tax,
        ];
    }

    /** The invoice that started the current period (not a proration). */
    private function currentPeriodInvoice(Subscription $subscription): ?Invoice
    {
        if ($subscription->current_period_started_at === null) {
            return null;
        }

        return Invoice::query()->withoutTenantScope()->where('subscription_id', $subscription->id)
            ->where('period_start', $subscription->current_period_started_at)
            ->where('billing_reason', '!=', BillingReason::Proration->value)
            ->first();
    }


    /** @return list<string> feature keys $a includes and $b does not */
    private function featureDiff(Package $a, Package $b): array
    {
        $on = fn (Package $p) => $p->entitlements()->where('type', EntitlementType::Feature->value)->where('boolean_value', true)->pluck('key')->all();

        return array_values(array_diff($on($a), $on($b)));
    }
}
