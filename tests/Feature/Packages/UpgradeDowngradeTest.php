<?php

declare(strict_types=1);

namespace Tests\Feature\Packages;

use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\UsageCounter;
use App\Domain\Packages\Services\SubscriptionLifecycleService;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * This milestone's "B2 Tests / UPGRADE/DOWNGRADE" section — Module 04
 * §20-22's exact requirements: same store, same data, no destructive
 * auto-deletion on downgrade.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class UpgradeDowngradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_package_change_preserves_store_id_and_tenant_identity(): void
    {
        $store = Store::factory()->create();
        $basic = Package::factory()->create(['code' => 'basic']);
        $premium = Package::factory()->create(['code' => 'premium']);
        Subscription::factory()->for($store)->for($basic)->create();

        $storeIdBefore = $store->id;

        $this->app->make(SubscriptionLifecycleService::class)
            ->changePackage($store, $premium, 'test-actor', 'test');

        $store->refresh();
        $this->assertSame($storeIdBefore, $store->id);
    }

    public function test_upgrade_changes_package_reference_only(): void
    {
        $store = Store::factory()->create();
        $basic = Package::factory()->create(['code' => 'basic']);
        $premium = Package::factory()->create(['code' => 'premium']);
        Subscription::factory()->for($store)->for($basic)->create();

        $this->app->make(SubscriptionLifecycleService::class)
            ->changePackage($store, $premium, 'test-actor', 'test');

        $subscription = Subscription::query()->withoutTenantScope()->where('store_id', $store->id)->firstOrFail();
        $this->assertSame($premium->id, $subscription->package_id);
    }

    public function test_downgrade_does_not_delete_any_business_data(): void
    {
        $store = Store::factory()->create();
        $business = Package::factory()->create(['code' => 'business']);
        $basic = Package::factory()->create(['code' => 'basic']);
        $basic->entitlements()->create([
            'key' => 'max_products', 'type' => EntitlementType::UsageLimit, 'limit_value' => 500,
        ]);
        Subscription::factory()->for($store)->for($business)->create();

        // Simulate the store having used far more than Basic's limit
        // (Module 04 §22's own worked example: 2,000 products vs 500).
        UsageCounter::query()->withoutTenantScope()->create([
            'store_id' => $store->id,
            'metric_key' => 'max_products',
            'period_start' => \Carbon\CarbonImmutable::createFromTimestamp(0),
            'period_end' => \Carbon\CarbonImmutable::createFromTimestamp(0)->addYears(100),
            'count' => 2000,
        ]);

        $overLimit = $this->app->make(SubscriptionLifecycleService::class)
            ->changePackage($store, $basic, 'test-actor', 'test');

        // The over-limit condition is reported, not silently resolved by deletion.
        $this->assertArrayHasKey('max_products', $overLimit);
        $this->assertSame(2000, $overLimit['max_products']['current']);

        // Nothing was deleted — the usage counter itself is untouched.
        $this->assertSame(
            2000,
            UsageCounter::query()->withoutTenantScope()
                ->where('store_id', $store->id)->where('metric_key', 'max_products')->value('count')
        );
    }

    public function test_downgrade_within_new_limit_reports_no_over_limit_usage(): void
    {
        $store = Store::factory()->create();
        $business = Package::factory()->create(['code' => 'business']);
        $basic = Package::factory()->create(['code' => 'basic']);
        $basic->entitlements()->create([
            'key' => 'max_products', 'type' => EntitlementType::UsageLimit, 'limit_value' => 500,
        ]);
        Subscription::factory()->for($store)->for($business)->create();

        UsageCounter::query()->withoutTenantScope()->create([
            'store_id' => $store->id,
            'metric_key' => 'max_products',
            'period_start' => \Carbon\CarbonImmutable::createFromTimestamp(0),
            'period_end' => \Carbon\CarbonImmutable::createFromTimestamp(0)->addYears(100),
            'count' => 100,
        ]);

        $overLimit = $this->app->make(SubscriptionLifecycleService::class)
            ->changePackage($store, $basic, 'test-actor', 'test');

        $this->assertSame([], $overLimit);
    }

    public function test_package_change_writes_an_audit_log_entry(): void
    {
        $store = Store::factory()->create();
        $basic = Package::factory()->create(['code' => 'basic']);
        $premium = Package::factory()->create(['code' => 'premium']);
        Subscription::factory()->for($store)->for($basic)->create();

        \Illuminate\Support\Facades\Log::shouldReceive('channel')->with('audit')->andReturnSelf();
        \Illuminate\Support\Facades\Log::shouldReceive('info')->once()->with('package_changed', \Mockery::type('array'));

        $this->app->make(SubscriptionLifecycleService::class)
            ->changePackage($store, $premium, 'test-actor', 'test');
    }
}
