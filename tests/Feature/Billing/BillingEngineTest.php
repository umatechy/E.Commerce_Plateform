<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\BillingInterval;
use App\Domain\Billing\Models\BillingReason;
use App\Domain\Billing\Models\InvoicePaymentMethod;
use App\Domain\Billing\Models\InvoiceStatus;
use App\Domain\Billing\Models\PackagePrice;
use App\Domain\Billing\Services\InvoiceService;
use App\Domain\Billing\Services\SubscriptionBillingService;
use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Packages\Services\SubscriptionLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B23 — Module 29 renewal engine (`billing:run`): invoices are
 * issued once and ahead of time, paid periods roll forward, unpaid ones
 * walk the dunning ladder, and nothing happens to a store the platform
 * forgot to price.
 */
final class BillingEngineTest extends TestCase
{
    use InteractsWithBilling, RefreshDatabase;

    private CarbonImmutable $trialEnd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-03-01 09:00:00'));
        $this->trialEnd = CarbonImmutable::parse('2026-03-15 09:00:00');
    }

    private function pay(\App\Domain\Billing\Models\Invoice $invoice, ?int $amount = null, string $key = 'bank-ref-0001'): void
    {
        app(InvoiceService::class)->recordPayment($invoice, [
            'amount_minor' => $amount ?? $invoice->total_minor - $invoice->amount_paid_minor,
            'method' => InvoicePaymentMethod::BankTransfer,
            'reference' => 'TRX-889',
            'received_at' => CarbonImmutable::now(),
            'idempotency_key' => $key,
        ], null);
    }

    public function test_the_first_invoice_is_issued_a_week_before_the_trial_ends_exactly_once(): void
    {
        config(['billing.tax_rate_bps' => 1700]);
        [$store, $owner, $subscription] = $this->billedStore(2900, periodEnd: $this->trialEnd);

        $this->travelTo($this->trialEnd->subDays(8));
        $this->runBilling();
        $this->assertCount(0, $this->invoicesOf($subscription));

        $this->travelTo($this->trialEnd->subDays(7));
        $this->runBilling();
        $this->runBilling();

        $invoice = $this->invoicesOf($subscription)->sole();
        $this->assertSame('INV-000001', $invoice->number);
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertSame(BillingReason::SubscriptionStart, $invoice->billing_reason);
        $this->assertSame([2900, 493, 3393], [$invoice->subtotal_minor, $invoice->tax_minor, $invoice->total_minor]);
        $this->assertSame('2026-03-15 09:00:00', $invoice->period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-15 09:00:00', $invoice->period_end->format('Y-m-d H:i:s'));
        $this->assertTrue($invoice->due_at->equalTo($this->trialEnd));
        $this->assertEquals(['store' => 'Acme Goods', 'store_id' => $store->public_id, 'email' => $owner->email], $invoice->bill_to);
        $this->assertSame('Business plan (monthly), 2026-03-15 to 2026-04-15', $invoice->lines()->sole()->description);

        $this->assertSame(SubscriptionStatus::Trialing, $subscription->refresh()->status);
        $this->assertSame(['billing.invoice_issued'], $this->outboxTypes($store));
        $this->assertTrue(AuditLog::query()->where('action', 'billing.invoice_issued')->where('store_id', $store->id)->exists());
    }

    public function test_paying_early_converts_the_trial_when_the_paid_period_starts_and_the_next_invoice_follows(): void
    {
        [$store, , $subscription] = $this->billedStore(2900, periodEnd: $this->trialEnd);
        $this->travelTo($this->trialEnd->subDays(7));
        $this->runBilling();
        $this->pay($this->invoicesOf($subscription)->sole());

        $this->assertSame(SubscriptionStatus::Trialing, $subscription->refresh()->status); // the paid period has not started yet

        $this->travelTo($this->trialEnd);
        $this->runBilling();

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame('2026-03-15 09:00:00', $subscription->current_period_started_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-15 09:00:00', $subscription->current_period_ends_at->format('Y-m-d H:i:s'));
        $this->assertTrue(AuditLog::query()->where('action', 'subscription_activated')->exists());

        $this->travelTo(CarbonImmutable::parse('2026-04-08 09:00:00'));
        $this->runBilling();

        $renewal = $this->invoicesOf($subscription)->last();
        $this->assertSame('INV-000002', $renewal->number);
        $this->assertSame(BillingReason::SubscriptionCycle, $renewal->billing_reason);
        $this->assertSame('2026-05-15 09:00:00', $renewal->period_end->format('Y-m-d H:i:s'));
        $this->assertSame(
            ['billing.invoice_issued', 'billing.invoice_paid', 'billing.subscription_renewed', 'billing.invoice_issued'],
            $this->outboxTypes($store),
        );
    }

    public function test_an_unpaid_invoice_walks_the_dunning_ladder_to_expiry(): void
    {
        [$store, , $subscription] = $this->billedStore(2900, periodEnd: $this->trialEnd);
        $ladder = [
            [0, SubscriptionStatus::PastDue, true],
            [3, SubscriptionStatus::GracePeriod, true],
            [10, SubscriptionStatus::Suspended, false],
            [40, SubscriptionStatus::Expired, false],
        ];

        foreach ($ladder as [$days, $status, $grantsAccess]) {
            $this->travelTo($this->trialEnd->addDays($days));
            $this->runBilling();
            $subscription->refresh();

            $this->assertSame($status, $subscription->status, "day {$days}");
            $this->assertSame($grantsAccess, $subscription->status->grantsAccess());
        }

        $this->assertSame('2026-03-25 09:00:00', $subscription->grace_period_ends_at->format('Y-m-d H:i:s'));
        $this->assertSame(InvoiceStatus::Uncollectible, $this->invoicesOf($subscription)->sole()->status);
        $this->assertSame(4, collect($this->outboxTypes($store))->filter(fn ($t) => $t === 'billing.payment_overdue')->count());
        $this->assertSame(
            ['subscription_past_due', 'subscription_grace_period', 'subscription_suspended', 'subscription_expired'],
            AuditLog::query()->where('store_id', $store->id)->where('action', 'like', 'subscription_%')->orderBy('sequence')->pluck('action')->all(),
        );
    }

    public function test_an_invoice_issued_late_is_due_when_issued_so_the_ladder_never_skips_ahead(): void
    {
        [, , $subscription] = $this->billedStore(2900, periodEnd: $this->trialEnd);

        // The scheduler did not run for ten days: the store must not be
        // suspended for an invoice it is only receiving now.
        $this->travelTo($this->trialEnd->addDays(10));
        $this->runBilling();

        $invoice = $this->invoicesOf($subscription)->sole();
        $this->assertTrue($invoice->due_at->equalTo(now()));
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->refresh()->status);
    }

    public function test_paying_a_suspended_store_reactivates_it_at_once_and_it_stays_active(): void
    {
        [, , $subscription] = $this->billedStore(2900, periodEnd: $this->trialEnd);
        $this->travelTo($this->trialEnd);
        $this->runBilling();
        $this->travelTo($this->trialEnd->addDays(10));
        $this->runBilling();
        $this->assertSame(SubscriptionStatus::Suspended, $subscription->refresh()->status);

        $this->pay($this->invoicesOf($subscription)->sole());

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNull($subscription->billing_suspended_at);
        $this->assertNull($subscription->grace_period_ends_at);
        $this->assertSame('2026-04-15 09:00:00', $subscription->current_period_ends_at->format('Y-m-d H:i:s'));

        $this->travelTo($this->trialEnd->addDays(11));
        $this->runBilling();
        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertCount(1, $this->invoicesOf($subscription));
    }

    public function test_a_partial_payment_keeps_the_invoice_open_and_the_dunning_going(): void
    {
        [, , $subscription] = $this->billedStore(2900, periodEnd: $this->trialEnd);
        $this->travelTo($this->trialEnd);
        $this->runBilling();
        $invoice = $this->invoicesOf($subscription)->sole();

        $this->pay($invoice, 1000);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertSame(1900, $invoice->amountDue());
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->refresh()->status);
    }

    public function test_a_package_without_a_price_is_never_invoiced_or_dunned(): void
    {
        [, , $subscription] = $this->billedStore(null, periodEnd: $this->trialEnd);

        $this->travelTo($this->trialEnd->addDays(20));
        $this->artisan('billing:run')->assertSuccessful()->expectsOutputToContain('unpriced');

        $this->assertCount(0, $this->invoicesOf($subscription));
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->refresh()->status);
    }

    public function test_a_free_package_renews_without_any_payment(): void
    {
        [$store, , $subscription] = $this->billedStore(0, status: 'active', periodEnd: $this->trialEnd);

        $this->travelTo($this->trialEnd->addHour());
        $this->runBilling();

        $invoice = $this->invoicesOf($subscription)->sole();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(0, $invoice->total_minor);
        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertSame('2026-04-15 09:00:00', $subscription->current_period_ends_at->format('Y-m-d H:i:s'));
        $this->assertNotContains('billing.invoice_paid', $this->outboxTypes($store));
    }

    public function test_a_scheduled_cancellation_ends_the_subscription_and_voids_the_unpaid_renewal(): void
    {
        [, , $subscription] = $this->billedStore(2900, status: 'active', periodEnd: $this->trialEnd);
        $this->travelTo($this->trialEnd->subDays(7));
        $this->runBilling();
        app(SubscriptionBillingService::class)->scheduleCancellation($subscription, 'Closing the shop');

        $this->travelTo($this->trialEnd);
        $this->runBilling();

        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->refresh()->status);
        $invoice = $this->invoicesOf($subscription)->sole();
        $this->assertSame(InvoiceStatus::Void, $invoice->status);
        $this->assertSame('subscription_cancelled', $invoice->void_reason);
    }

    public function test_a_cancellation_after_paying_for_the_next_period_takes_effect_after_that_period(): void
    {
        [, , $subscription] = $this->billedStore(2900, status: 'active', periodEnd: $this->trialEnd);
        $this->travelTo($this->trialEnd->subDays(7));
        $this->runBilling();
        $this->pay($this->invoicesOf($subscription)->sole());
        app(SubscriptionBillingService::class)->scheduleCancellation($subscription, null);

        $this->travelTo($this->trialEnd);
        $this->runBilling();
        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);

        $this->travelTo(CarbonImmutable::parse('2026-04-10 09:00:00'));
        $this->runBilling();
        $this->assertCount(1, $this->invoicesOf($subscription)); // no renewal invoice once cancellation is scheduled

        $this->travelTo(CarbonImmutable::parse('2026-04-15 09:00:00'));
        $this->runBilling();
        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->refresh()->status);
    }

    public function test_a_manual_suspension_pauses_billing_and_a_payment_does_not_lift_it(): void
    {
        [$store, , $subscription] = $this->billedStore(2900, status: 'active', periodEnd: $this->trialEnd);
        $this->travelTo($this->trialEnd);
        $this->runBilling();
        app(SubscriptionLifecycleService::class)->suspend($store, 'fraud_review');

        $this->travelTo($this->trialEnd->addDays(45));
        $this->runBilling();
        $this->assertSame(SubscriptionStatus::Suspended, $subscription->refresh()->status);
        $this->assertNull($subscription->billing_suspended_at);

        $this->pay($this->invoicesOf($subscription)->sole());
        $this->assertSame(SubscriptionStatus::Suspended, $subscription->refresh()->status);
    }

    public function test_reactivating_an_expired_subscription_restarts_billing_from_today(): void
    {
        [$store, , $subscription] = $this->billedStore(2900, periodEnd: $this->trialEnd);
        $this->travelTo($this->trialEnd);
        $this->runBilling();
        $this->travelTo($this->trialEnd->addDays(40));
        $this->runBilling();
        $this->assertSame(SubscriptionStatus::Expired, $subscription->refresh()->status);

        app(SubscriptionLifecycleService::class)->reactivate($store, 'customer_returned');
        $this->runBilling();

        $restart = $this->invoicesOf($subscription)->last();
        $this->assertSame(BillingReason::SubscriptionStart, $restart->billing_reason);
        $this->assertSame(now()->format('Y-m-d'), $restart->period_start->format('Y-m-d'));
        $this->assertSame(InvoiceStatus::Uncollectible, $this->invoicesOf($subscription)->first()->status);
    }

    public function test_yearly_billing_and_month_end_anchors(): void
    {
        [, , $subscription] = $this->billedStore(29000, status: 'active', periodEnd: $this->trialEnd, interval: 'yearly');
        $this->travelTo($this->trialEnd);
        $this->runBilling();
        $this->assertSame('2027-03-15', $this->invoicesOf($subscription)->sole()->period_end->format('Y-m-d'));

        $anchor = CarbonImmutable::parse('2027-01-31 00:00:00');
        $monthly = BillingInterval::Monthly;
        $this->assertSame('2027-02-28', $monthly->periodEnd($anchor, $anchor)->toDateString());
        $this->assertSame('2027-03-31', $monthly->periodEnd(CarbonImmutable::parse('2027-02-28'), $anchor)->toDateString());
        $this->assertSame('2029-02-28', BillingInterval::Yearly->periodEnd(CarbonImmutable::parse('2028-02-29'), CarbonImmutable::parse('2028-02-29'))->toDateString());
    }

    public function test_price_changes_apply_from_the_next_invoice_only(): void
    {
        [, , $subscription, $package] = $this->billedStore(2900, status: 'active', periodEnd: $this->trialEnd);
        $this->travelTo($this->trialEnd->subDays(7));
        $this->runBilling();

        PackagePrice::query()->where('package_id', $package->id)->update(['amount_minor' => 3900]);
        $this->pay($this->invoicesOf($subscription)->sole());
        $this->travelTo(CarbonImmutable::parse('2026-04-08 09:00:00'));
        $this->runBilling();

        $this->assertSame([2900, 3900], $this->invoicesOf($subscription)->pluck('total_minor')->all());
    }
}
