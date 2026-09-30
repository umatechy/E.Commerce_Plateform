<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Exceptions\BillingActionRefusedException;
use App\Domain\Billing\Models\BillingInterval;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use Illuminate\Support\Facades\DB;

/**
 * Module 29 — what a store owner may change about their own billing.
 * None of these take effect before the current period ends: the store
 * keeps what it paid for.
 */
final class SubscriptionBillingService
{
    private const CANCELLABLE = [
        SubscriptionStatus::Trialing, SubscriptionStatus::Active, SubscriptionStatus::PastDue, SubscriptionStatus::GracePeriod,
    ];

    public function __construct(private readonly InvoiceLedger $ledger) {}

    /** @throws BillingActionRefusedException */
    public function scheduleCancellation(Subscription $subscription, ?string $reason): Subscription
    {
        return $this->locked($subscription, function (Subscription $subscription) use ($reason) {
            if (! in_array($subscription->status, self::CANCELLABLE, true)) {
                throw new BillingActionRefusedException('not_cancellable', "A {$subscription->status->value} subscription cannot be cancelled.");
            }

            if ($subscription->cancel_at_period_end) {
                throw new BillingActionRefusedException('already_scheduled', 'Cancellation is already scheduled for the end of the period.');
            }

            $subscription->update(['cancel_at_period_end' => true, 'cancellation_requested_at' => now()]);

            app(AuditLogger::class)->record('billing.cancellation_scheduled', [
                'effective_at' => $subscription->current_period_ends_at, 'reason' => $reason,
            ], $subscription, $subscription->store_id);
        });
    }

    /** @throws BillingActionRefusedException */
    public function resume(Subscription $subscription): Subscription
    {
        return $this->locked($subscription, function (Subscription $subscription) {
            if (! $subscription->cancel_at_period_end || $subscription->status === SubscriptionStatus::Cancelled) {
                throw new BillingActionRefusedException('not_scheduled', 'No cancellation is scheduled for this subscription.');
            }

            $subscription->update(['cancel_at_period_end' => false, 'cancellation_requested_at' => null]);

            app(AuditLogger::class)->record('billing.cancellation_withdrawn', [], $subscription, $subscription->store_id);
        });
    }

    /**
     * Switches monthly/yearly from the next period on. Refused once that
     * period is invoiced, so an issued invoice never disagrees with the
     * subscription it bills.
     *
     * @throws BillingActionRefusedException
     */
    public function changeInterval(Subscription $subscription, BillingInterval $interval): Subscription
    {
        return $this->locked($subscription, function (Subscription $subscription) use ($interval) {
            $current = $this->ledger->intervalOf($subscription);

            if ($current === $interval) {
                throw new BillingActionRefusedException('interval_unchanged', "The subscription is already billed {$interval->value}.", 422);
            }

            if ($this->ledger->nextPeriodInvoice($subscription) !== null) {
                throw new BillingActionRefusedException('next_invoice_already_issued', 'The next period is already invoiced; change the interval after it starts.');
            }

            if ($this->ledger->priceFor($subscription, $interval) === null) {
                throw new BillingActionRefusedException('no_price_for_interval', "The current package has no {$interval->value} price.", 422);
            }

            // The new cycle is anchored at the start of the next period.
            $subscription->update(['billing_interval' => $interval, 'billing_anchor_at' => $subscription->current_period_ends_at]);

            app(AuditLogger::class)->record('billing.interval_changed', [
                'from' => $current, 'to' => $interval, 'effective_at' => $subscription->current_period_ends_at,
            ], $subscription, $subscription->store_id);
        });
    }

    /** @param callable(Subscription): void $change */
    private function locked(Subscription $subscription, callable $change): Subscription
    {
        return DB::transaction(function () use ($subscription, $change) {
            $locked = Subscription::query()->withoutTenantScope()->lockForUpdate()->findOrFail($subscription->id);
            $change($locked);

            return $locked;
        });
    }
}
