<?php

declare(strict_types=1);

namespace Tests\Feature\Packages;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Exceptions\FeatureNotEntitledException;
use App\Domain\Packages\Exceptions\SubscriptionInactiveException;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * This milestone's "B2 Tests / ENTITLEMENTS" section.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class EntitlementTest extends TestCase
{
    use RefreshDatabase;

    private function storeWithSubscription(SubscriptionStatus $status, array $features = []): Store
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create();

        foreach ($features as $key => $enabled) {
            $package->entitlements()->create([
                'key' => $key,
                'type' => EntitlementType::Feature,
                'boolean_value' => $enabled,
            ]);
        }

        Subscription::factory()->for($store)->for($package)->create(['status' => $status]);

        return $store;
    }

    private function resolveContextTo(Store $store): EntitlementService
    {
        $context = $this->app->make(TenantContext::class);
        $context->resolveToStore($store->id);

        return $this->app->make(EntitlementService::class);
    }

    public function test_enabled_feature_is_entitled(): void
    {
        $store = $this->storeWithSubscription(SubscriptionStatus::Active, ['feature.a' => true]);
        $service = $this->resolveContextTo($store);

        $this->assertTrue($service->hasFeature('feature.a'));
    }

    public function test_disabled_feature_is_not_entitled(): void
    {
        $store = $this->storeWithSubscription(SubscriptionStatus::Active, ['feature.a' => false]);
        $service = $this->resolveContextTo($store);

        $this->assertFalse($service->hasFeature('feature.a'));
    }

    public function test_missing_feature_key_is_not_entitled(): void
    {
        $store = $this->storeWithSubscription(SubscriptionStatus::Active);
        $service = $this->resolveContextTo($store);

        $this->assertFalse($service->hasFeature('feature.never-configured'));
    }

    public function test_assert_feature_entitled_throws_when_subscription_inactive(): void
    {
        $store = $this->storeWithSubscription(SubscriptionStatus::Suspended, ['feature.a' => true]);
        $service = $this->resolveContextTo($store);

        $this->expectException(SubscriptionInactiveException::class);
        $service->assertFeatureEntitled('feature.a');
    }

    public function test_assert_feature_entitled_throws_when_feature_disabled_despite_active_subscription(): void
    {
        $store = $this->storeWithSubscription(SubscriptionStatus::Active, ['feature.a' => false]);
        $service = $this->resolveContextTo($store);

        $this->expectException(FeatureNotEntitledException::class);
        $service->assertFeatureEntitled('feature.a');
    }

    public function test_trialing_subscription_grants_feature_access(): void
    {
        $store = $this->storeWithSubscription(SubscriptionStatus::Trialing, ['feature.a' => true]);
        $service = $this->resolveContextTo($store);

        $service->assertFeatureEntitled('feature.a'); // must not throw
        $this->assertTrue(true);
    }

    public function test_grace_period_subscription_grants_feature_access(): void
    {
        $store = $this->storeWithSubscription(SubscriptionStatus::GracePeriod, ['feature.a' => true]);
        $service = $this->resolveContextTo($store);

        $service->assertFeatureEntitled('feature.a'); // must not throw
        $this->assertTrue(true);
    }

    public function test_cancelled_subscription_denies_feature_access(): void
    {
        $store = $this->storeWithSubscription(SubscriptionStatus::Cancelled, ['feature.a' => true]);
        $service = $this->resolveContextTo($store);

        $this->expectException(SubscriptionInactiveException::class);
        $service->assertFeatureEntitled('feature.a');
    }
}
