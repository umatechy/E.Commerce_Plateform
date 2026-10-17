<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Billing\Models\BillingInterval;
use App\Domain\Billing\Models\InvoiceStatus;
use App\Domain\Billing\Models\PackagePrice;
use App\Domain\Billing\Services\InvoiceService;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Services\SubscriptionLifecycleService;
use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Owner decision (2026-10-17): a package is bought with a one-time price
 * that includes the first year, then renewed every year at the yearly
 * price. Both prices are set, and changed, by Umar Techy.
 */
final class OneTimePriceTest extends TestCase
{
    use InteractsWithBilling, RefreshDatabase;

    private CarbonImmutable $trialEnd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-03-01 09:00:00'));
        $this->trialEnd = CarbonImmutable::parse('2026-03-15 09:00:00');
    }

    private function pay(\App\Domain\Billing\Models\Invoice $invoice, string $key): void
    {
        app(InvoiceService::class)->recordPayment($invoice, [
            'amount_minor' => $invoice->total_minor,
            'method' => \App\Domain\Billing\Models\InvoicePaymentMethod::BankTransfer,
            'reference' => 'TRX-889',
            'received_at' => CarbonImmutable::now(),
            'idempotency_key' => $key,
        ], null);
    }

    public function test_the_first_year_costs_the_one_time_price_and_later_years_the_renewal_price(): void
    {
        config(['billing.tax_rate_bps' => 0]);
        [, , $subscription, $package] = $this->billedStore(600000, periodEnd: $this->trialEnd, interval: 'yearly');
        PackagePrice::query()->where('package_id', $package->id)->update(['first_period_amount_minor' => 2000000]);

        $this->travelTo($this->trialEnd->subDays(7));
        $this->runBilling();
        $first = $this->invoicesOf($subscription)->sole();
        $this->assertSame(2000000, $first->total_minor);
        $this->assertSame('Business package, one-time price including 2026-03-15 to 2027-03-15', $first->lines()->sole()->description);

        $this->pay($first, 'bank-ref-0001');
        $this->travelTo(CarbonImmutable::parse('2027-03-08 09:00:00'));
        $this->runBilling();

        $renewal = $this->invoicesOf($subscription)->last();
        $this->assertSame(600000, $renewal->total_minor);
        $this->assertSame('Business plan (yearly), 2027-03-15 to 2028-03-15', $renewal->lines()->sole()->description);
    }

    public function test_a_voided_first_invoice_does_not_use_up_the_one_time_price(): void
    {
        config(['billing.tax_rate_bps' => 0]);
        [, , $subscription, $package] = $this->billedStore(600000, periodEnd: $this->trialEnd, interval: 'yearly');
        PackagePrice::query()->where('package_id', $package->id)->update(['first_period_amount_minor' => 2000000]);

        $this->travelTo($this->trialEnd->subDays(7));
        $this->runBilling();
        app(InvoiceService::class)->void($this->invoicesOf($subscription)->sole(), 'Wrong package');

        $ledger = app(\App\Domain\Billing\Services\InvoiceLedger::class);
        $this->assertSame(InvoiceStatus::Void, $this->invoicesOf($subscription)->sole()->status);
        $this->assertTrue($ledger->isFirstPeriod($subscription->refresh()));
        $quote = $ledger->quoteNextPeriod($subscription);
        $this->assertTrue($quote['first_period']);
        $this->assertSame(2000000, $quote['total_minor']);
    }

    public function test_without_a_one_time_price_every_period_costs_the_renewal_price(): void
    {
        config(['billing.tax_rate_bps' => 0]);
        [, , $subscription] = $this->billedStore(600000, periodEnd: $this->trialEnd, interval: 'yearly');

        $this->travelTo($this->trialEnd->subDays(7));
        $this->runBilling();
        $this->assertSame(600000, $this->invoicesOf($subscription)->sole()->total_minor);
    }

    public function test_new_stores_start_on_the_platform_renewal_interval(): void
    {
        $package = Package::factory()->create();
        $lifecycle = app(SubscriptionLifecycleService::class);

        $this->assertSame(BillingInterval::Yearly, $lifecycle->startTrial(Store::factory()->create(), $package)->billing_interval);

        app(ConfigService::class)->set('billing.default_interval', 'monthly', SettingScope::Platform, null);
        $this->assertSame(BillingInterval::Monthly, $lifecycle->startTrial(Store::factory()->create(), $package)->billing_interval);
    }

    public function test_super_admin_sets_and_clears_the_one_time_price(): void
    {
        $admin = User::factory()->create(['platform_role' => 'super_admin']);
        Package::factory()->create(['code' => 'basic']);

        $created = $this->actingAs($admin)->postJson('/api/v1/super-admin/billing/prices', [
            'package_code' => 'basic', 'billing_interval' => 'yearly', 'currency' => 'PKR', 'amount_minor' => 600000, 'first_period_amount_minor' => 2000000,
        ])->assertCreated()->assertJsonPath('data.first_period_amount_minor', 2000000);

        $this->actingAs($admin)->postJson('/api/v1/super-admin/billing/prices', [
            'package_code' => 'basic', 'billing_interval' => 'yearly', 'currency' => 'PKR', 'amount_minor' => 600000, 'first_period_amount_minor' => -5,
        ])->assertStatus(422)->assertJsonValidationErrors(['first_period_amount_minor']);

        $this->actingAs($admin)->patchJson('/api/v1/super-admin/billing/prices/'.$created->json('data.id'), ['first_period_amount_minor' => null])
            ->assertOk()->assertJsonPath('data.first_period_amount_minor', null);
    }

    public function test_the_home_page_shows_the_yearly_price_with_its_one_time_price(): void
    {
        $package = Package::factory()->create(['name' => 'Basic', 'code' => 'basic']);
        PackagePrice::query()->create(['package_id' => $package->id, 'billing_interval' => 'monthly', 'currency' => 'PKR', 'amount_minor' => 99900]);
        PackagePrice::query()->create(['package_id' => $package->id, 'billing_interval' => 'yearly', 'currency' => 'PKR', 'amount_minor' => 600000, 'first_period_amount_minor' => 2000000]);
        config(['billing.currency' => 'PKR']);

        $this->withoutVite()->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('plans.0.price.interval', 'yearly')
            ->where('plans.0.price.amount_minor', 600000)
            ->where('plans.0.price.first_period_amount_minor', 2000000));
    }
}
