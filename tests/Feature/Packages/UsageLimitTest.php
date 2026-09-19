<?php

declare(strict_types=1);

namespace Tests\Feature\Packages;

use App\Domain\Packages\Exceptions\UsageLimitExceededException;
use App\Domain\Packages\Models\EntitlementEnforcement;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Packages\Models\UsagePeriod;
use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * This milestone's "B2 Tests / USAGE" and "Concurrency" sections.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class UsageLimitTest extends TestCase
{
    use RefreshDatabase;

    private function storeWithLimit(
        int $limit,
        EntitlementEnforcement $enforcement = EntitlementEnforcement::Hard,
        UsagePeriod $period = UsagePeriod::Persistent,
        bool $unlimited = false,
    ): Store {
        $store = Store::factory()->create();
        $package = Package::factory()->create();
        $package->entitlements()->create([
            'key' => 'max_widgets',
            'type' => EntitlementType::UsageLimit,
            'enforcement' => $enforcement,
            'period' => $period,
            'limit_value' => $limit,
            'is_unlimited' => $unlimited,
        ]);
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);

        return $store;
    }

    private function serviceFor(Store $store): EntitlementService
    {
        $this->app->make(TenantContext::class)->resolveToStore($store->id);

        return $this->app->make(EntitlementService::class);
    }

    public function test_usage_under_limit_is_allowed(): void
    {
        $store = $this->storeWithLimit(10);
        $service = $this->serviceFor($store);

        $service->recordUsage('max_widgets', 3);

        $this->assertTrue($service->isWithinLimit('max_widgets'));
        $service->assertWithinLimit('max_widgets'); // must not throw
        $this->assertTrue(true);
    }

    public function test_usage_at_limit_blocks_hard_enforced_metric(): void
    {
        $store = $this->storeWithLimit(5, EntitlementEnforcement::Hard);
        $service = $this->serviceFor($store);

        $service->recordUsage('max_widgets', 5);

        $this->expectException(UsageLimitExceededException::class);
        $service->assertWithinLimit('max_widgets');
    }

    public function test_usage_over_soft_limit_does_not_throw(): void
    {
        $store = $this->storeWithLimit(5, EntitlementEnforcement::Soft);
        $service = $this->serviceFor($store);

        $service->recordUsage('max_widgets', 10); // already over the soft limit

        $service->assertWithinLimit('max_widgets'); // must not throw for Soft
        $this->assertFalse($service->isWithinLimit('max_widgets')); // but is reported as over
    }

    public function test_unlimited_entitlement_is_never_blocked(): void
    {
        $store = $this->storeWithLimit(5, EntitlementEnforcement::Hard, unlimited: true);
        $service = $this->serviceFor($store);

        $service->recordUsage('max_widgets', 100_000);

        $this->assertNull($service->limitFor('max_widgets'));
        $service->assertWithinLimit('max_widgets'); // must not throw
        $this->assertTrue(true);
    }

    public function test_missing_usage_limit_entitlement_is_treated_as_unlimited(): void
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create(); // no entitlement rows at all
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $service = $this->serviceFor($store);

        $this->assertNull($service->limitFor('never_configured_metric'));
        $service->assertWithinLimit('never_configured_metric');
        $this->assertTrue(true);
    }

    public function test_release_usage_decrements_counter(): void
    {
        $store = $this->storeWithLimit(10);
        $service = $this->serviceFor($store);

        $service->recordUsage('max_widgets', 5);
        $service->releaseUsage('max_widgets', 2);

        $this->assertSame(3, $service->currentUsage('max_widgets'));
    }

    public function test_release_usage_never_drives_counter_negative(): void
    {
        $store = $this->storeWithLimit(10);
        $service = $this->serviceFor($store);

        $service->releaseUsage('max_widgets', 5); // nothing recorded yet

        $this->assertSame(0, $service->currentUsage('max_widgets'));
    }

    public function test_monthly_period_limit_is_tracked_independently_of_persistent_limits(): void
    {
        $store = $this->storeWithLimit(100, period: UsagePeriod::Monthly);
        $service = $this->serviceFor($store);

        $service->recordUsage('max_widgets', 10);

        $this->assertSame(10, $service->currentUsage('max_widgets'));
    }

    /**
     * Concurrency: two "simultaneous" increments (this milestone's exact
     * example scenario) must both land — the ON DUPLICATE KEY UPDATE
     * statement is atomic per-statement in MySQL, so even truly
     * concurrent requests cannot lose an increment. This test simulates
     * it sequentially (no real concurrent process available in this
     * environment) — a genuine concurrent-load test is deferred to the
     * VS Code phase with a real MySQL instance and parallel requests.
     */
    public function test_repeated_increments_do_not_lose_updates(): void
    {
        $store = $this->storeWithLimit(1000);
        $service = $this->serviceFor($store);

        for ($i = 0; $i < 50; $i++) {
            $service->recordUsage('max_widgets', 1);
        }

        $this->assertSame(50, $service->currentUsage('max_widgets'));
    }
}
