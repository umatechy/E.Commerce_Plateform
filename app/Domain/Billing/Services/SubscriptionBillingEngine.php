<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceStatus;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Packages\Services\SubscriptionLifecycleService;
use Carbon\CarbonImmutable;

/**
 * Module 29 — brings one subscription's billing up to date. Idempotent:
 * running it again with nothing new to do changes nothing, and each
 * step is guarded by state (the unique invoice-per-period index is the
 * backstop). The caller holds the subscription's row lock inside a
 * transaction (BillingRunner, InvoiceService).
 *
 * Order of work:
 *  1. roll the period forward over every period already paid or waived;
 *  2. at the period end: cancel if cancellation was scheduled, otherwise
 *     make sure the next period is invoiced (a zero-total invoice settles
 *     itself and rolls forward at once);
 *  3. before the period end: issue the renewal invoice `issue_days_before`
 *     days ahead;
 *  4. dunning: move along past_due → grace_period → suspended → expired
 *     by the oldest overdue invoice's age, or recover to active when
 *     nothing is overdue any more.
 */
final class SubscriptionBillingEngine
{
    /** Safety bound for step 1+2 (e.g. zero-total periods after a long outage). */
    private const MAX_PERIODS_PER_RUN = 36;

    /** How far along the dunning ladder each status is. */
    private const DUNNING_RANK = [
        'trialing' => 0, 'active' => 0, 'past_due' => 1, 'grace_period' => 2, 'suspended' => 3, 'expired' => 4,
    ];

    public function __construct(
        private readonly InvoiceLedger $ledger,
        private readonly SubscriptionLifecycleService $lifecycle,
        private readonly RecordsOutboxEvents $outbox,
    ) {}

    /** @return list<string> what was done, for the run summary */
    public function process(Subscription $subscription): array
    {
        $actions = [];

        if (! $this->isBillable($subscription)) {
            return $actions;
        }

        $now = CarbonImmutable::now();

        for ($i = 0; $i < self::MAX_PERIODS_PER_RUN; $i++) {
            $this->rollForward($subscription, $now, $actions);

            if ($subscription->current_period_ends_at->greaterThan($now)) {
                $this->issueAhead($subscription, $now, $actions);
                break;
            }

            if ($subscription->cancel_at_period_end) {
                $this->cancelAtPeriodEnd($subscription, $actions);

                return $actions;
            }

            $invoice = $this->issue($subscription, $actions);

            if ($invoice === null) {
                $actions[] = 'unpriced';
                break;
            }

            if (! $invoice->status->isSettled()) {
                break; // unpaid: dunning below decides what that means
            }
        }

        $this->applyDunning($subscription, $now, $actions);

        return $actions;
    }

    /**
     * Only subscriptions the engine owns: a manual Super Admin suspension
     * (billing_suspended_at null) pauses billing until it is lifted, and
     * pending/cancelled/expired/archived subscriptions are not billed.
     */
    public function isBillable(Subscription $subscription): bool
    {
        if ($subscription->current_period_ends_at === null) {
            return false;
        }

        return match ($subscription->status) {
            SubscriptionStatus::Trialing, SubscriptionStatus::Active, SubscriptionStatus::PastDue, SubscriptionStatus::GracePeriod => true,
            SubscriptionStatus::Suspended => $subscription->billing_suspended_at !== null,
            default => false,
        };
    }

    /** @param list<string> $actions */
    private function rollForward(Subscription $subscription, CarbonImmutable $now, array &$actions): void
    {
        while ($subscription->current_period_ends_at->lessThanOrEqualTo($now)) {
            $invoice = $this->ledger->nextPeriodInvoice($subscription);

            if ($invoice === null || ! $invoice->status->isSettled()) {
                return;
            }

            $period = ['current_period_started_at' => $invoice->period_start, 'current_period_ends_at' => $invoice->period_end];

            if ($subscription->status === SubscriptionStatus::Trialing) {
                $this->lifecycle->transitionLocked($subscription, SubscriptionStatus::Active, 'subscription_activated', 'trial_converted', $period);
            } else {
                $subscription->update($period);
            }

            // Phase B47 (Module 29 §49): the period was billed for another package — a
            // scheduled downgrade — which takes effect now, with the period it was paid for.
            if ($subscription->scheduled_package_id !== null && $invoice->package_id === $subscription->scheduled_package_id) {
                $this->lifecycle->changePackage(\App\Domain\Tenancy\Models\Store::query()->withTrashed()->findOrFail($subscription->store_id), \App\Domain\Packages\Models\Package::query()->findOrFail($invoice->package_id), 'billing engine', 'scheduled_change');
                $subscription->forceFill(['package_id' => $invoice->package_id, 'scheduled_package_id' => null])->save();
                $actions[] = 'package_changed';
            }

            app(AuditLogger::class)->record('billing.subscription_renewed', [
                'invoice' => $invoice->number, 'period_start' => $invoice->period_start, 'period_end' => $invoice->period_end,
            ], $subscription, $subscription->store_id);

            $this->outbox->recordEventFor($subscription->store_id, 'billing.subscription_renewed', [
                'subscription_id' => $subscription->id,
                'invoice_id' => $invoice->id,
                'period_start' => $invoice->period_start->toIso8601String(),
                'period_end' => $invoice->period_end->toIso8601String(),
            ], "subscription:{$subscription->id}:renewed:{$invoice->period_start->timestamp}");

            $actions[] = 'renewed';
        }
    }

    /** @param list<string> $actions */
    private function issueAhead(Subscription $subscription, CarbonImmutable $now, array &$actions): void
    {
        $issueFrom = $subscription->current_period_ends_at->copy()->subDays((int) config('billing.issue_days_before'));

        if (! $subscription->cancel_at_period_end && $issueFrom->lessThanOrEqualTo($now)) {
            $this->issue($subscription, $actions);
        }
    }

    /** @param list<string> $actions */
    private function issue(Subscription $subscription, array &$actions): ?Invoice
    {
        $existing = $this->ledger->nextPeriodInvoice($subscription);

        if ($existing !== null) {
            return $existing;
        }

        $invoice = $this->ledger->issueNextPeriod($subscription);

        if ($invoice !== null) {
            $actions[] = 'issued';
        }

        return $invoice;
    }

    /**
     * The subscription ends at the end of the last period that was paid
     * for. An invoice for the period that will now never start is voided.
     *
     * @param list<string> $actions
     */
    private function cancelAtPeriodEnd(Subscription $subscription, array &$actions): void
    {
        foreach ($this->openInvoices($subscription) as $invoice) {
            $this->ledger->markVoid($invoice, 'subscription_cancelled');
        }

        $this->lifecycle->transitionLocked($subscription, SubscriptionStatus::Cancelled, 'subscription_cancelled', 'cancelled_at_period_end', [
            'grace_period_ends_at' => null, 'billing_suspended_at' => null,
        ]);
        $actions[] = 'cancelled';
    }

    /** @param list<string> $actions */
    private function applyDunning(Subscription $subscription, CarbonImmutable $now, array &$actions): void
    {
        $overdue = $this->openInvoices($subscription)->first(fn (Invoice $invoice) => $invoice->isOverdue($now));

        if ($overdue === null) {
            if ((self::DUNNING_RANK[$subscription->status->value] ?? 0) > 0) {
                $this->lifecycle->transitionLocked($subscription, SubscriptionStatus::Active, 'subscription_reactivated', 'invoice_settled', [
                    'grace_period_ends_at' => null, 'billing_suspended_at' => null,
                ]);
                $actions[] = 'recovered';
            }

            return;
        }

        $dueAt = CarbonImmutable::instance($overdue->due_at);
        $dunning = config('billing.dunning');
        [$target, $attributes] = match (true) {
            $now->greaterThanOrEqualTo($dueAt->addDays((int) $dunning['expire_after_days'])) => [SubscriptionStatus::Expired, []],
            $now->greaterThanOrEqualTo($dueAt->addDays((int) $dunning['suspend_after_days'])) => [SubscriptionStatus::Suspended, ['billing_suspended_at' => $now]],
            $now->greaterThanOrEqualTo($dueAt->addDays((int) $dunning['grace_after_days'])) => [SubscriptionStatus::GracePeriod, ['grace_period_ends_at' => $dueAt->addDays((int) $dunning['suspend_after_days'])]],
            default => [SubscriptionStatus::PastDue, []],
        };

        // Dunning only moves forward; recovery happens above, on payment.
        if (self::DUNNING_RANK[$target->value] <= (self::DUNNING_RANK[$subscription->status->value] ?? 0)) {
            return;
        }

        if ($target === SubscriptionStatus::GracePeriod || $target === SubscriptionStatus::Suspended) {
            $attributes['grace_period_ends_at'] ??= $dueAt->addDays((int) $dunning['suspend_after_days']);
        }

        $this->lifecycle->transitionLocked($subscription, $target, "subscription_{$target->value}", 'unpaid_invoice', $attributes);

        if ($target === SubscriptionStatus::Expired) {
            foreach ($this->openInvoices($subscription) as $invoice) {
                $this->ledger->markUncollectible($invoice);
            }
        }

        $this->outbox->recordEventFor($subscription->store_id, 'billing.payment_overdue', [
            ...$this->ledger->payload($overdue),
            'stage' => $target->value,
        // Keyed by due date too: after a due-date extension the same
        // invoice can legitimately reach the same stage again.
        ], "invoice:{$overdue->id}:overdue:{$target->value}:{$overdue->due_at->timestamp}");

        $actions[] = $target->value;
    }

    /** @return \Illuminate\Support\Collection<int, Invoice> oldest due first */
    private function openInvoices(Subscription $subscription): \Illuminate\Support\Collection
    {
        return Invoice::query()->withoutTenantScope()
            ->where('subscription_id', $subscription->id)
            ->where('status', InvoiceStatus::Open->value)
            ->orderBy('due_at')
            ->lockForUpdate()
            ->get();
    }
}
