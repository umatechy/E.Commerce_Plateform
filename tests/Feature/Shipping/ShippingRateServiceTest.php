<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Domain\Shipping\Exceptions\DestinationNotServiceableException;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Shipping\Models\ShippingMethodType;
use App\Domain\Shipping\Models\ShippingZone;
use App\Domain\Shipping\Services\ShippingRateService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B8 — Zone priority, rate calculation, server-authoritative
 * shipping cost (Module 13 §8-9/§16-20/§38-40).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class ShippingRateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_more_specific_zone_wins_over_country_zone(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        $countryZone = ShippingZone::factory()->for($store)->create(['name' => 'Pakistan', 'country' => 'PK']);
        $cityZone = ShippingZone::factory()->for($store)->create(['name' => 'Lahore', 'country' => 'PK', 'city' => 'Lahore']);

        $resolved = app(ShippingRateService::class)->resolveZone(['country' => 'PK', 'city' => 'Lahore']);

        $this->assertSame($cityZone->id, $resolved->id);
    }

    public function test_falls_back_to_default_zone_when_nothing_matches(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        ShippingZone::factory()->for($store)->create(['name' => 'Pakistan', 'country' => 'PK']);
        // StoreObserver already gives every store its catch-all default
        // zone; a store has exactly one.
        $default = ShippingZone::query()->where('is_default', true)->sole();

        $resolved = app(ShippingRateService::class)->resolveZone(['country' => 'US']);

        $this->assertSame($default->id, $resolved->id);
    }

    public function test_flat_rate_cost_is_the_configured_base_cost(): void
    {
        [$zone, $method] = $this->zoneAndMethod(ShippingMethodType::FlatRate);
        \App\Domain\Shipping\Models\ShippingRate::factory()->for($zone, 'zone')->for($method, 'method')
            ->create(['base_cost_minor' => 350]);

        $quote = app(ShippingRateService::class)->quote($method->id, ['country' => 'PK'], 5000, [], 'USD');

        $this->assertSame(350, $quote['cost_minor']);
    }

    public function test_free_shipping_is_free_above_threshold(): void
    {
        [$zone, $method] = $this->zoneAndMethod(ShippingMethodType::Free);
        $method->update(['free_shipping_threshold_minor' => 5000]);
        \App\Domain\Shipping\Models\ShippingRate::factory()->for($zone, 'zone')->for($method, 'method')->create(['base_cost_minor' => 300]);

        $quote = app(ShippingRateService::class)->quote($method->id, ['country' => 'PK'], 6000, [], 'USD');

        $this->assertSame(0, $quote['cost_minor']);
    }

    public function test_free_shipping_charges_base_cost_below_threshold(): void
    {
        [$zone, $method] = $this->zoneAndMethod(ShippingMethodType::Free);
        $method->update(['free_shipping_threshold_minor' => 5000]);
        \App\Domain\Shipping\Models\ShippingRate::factory()->for($zone, 'zone')->for($method, 'method')->create(['base_cost_minor' => 300]);

        $quote = app(ShippingRateService::class)->quote($method->id, ['country' => 'PK'], 1000, [], 'USD');

        $this->assertSame(300, $quote['cost_minor']);
    }

    public function test_weight_based_cost_scales_with_total_weight(): void
    {
        [$zone, $method] = $this->zoneAndMethod(ShippingMethodType::WeightBased);
        \App\Domain\Shipping\Models\ShippingRate::factory()->for($zone, 'zone')->for($method, 'method')
            ->create(['base_cost_minor' => 100, 'per_unit_cost_minor' => 50]);

        $quote = app(ShippingRateService::class)->quote($method->id, ['country' => 'PK'], 5000, [['weight' => 2.4]], 'USD');

        $this->assertSame(100 + 3 * 50, $quote['cost_minor']); // ceil(2.4) = 3
    }

    public function test_unserviceable_destination_throws(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $method = ShippingMethod::factory()->for($store)->create(['type' => ShippingMethodType::FlatRate]);
        // No zone/rate configured at all for this store beyond StoreObserver's default pickup rate.

        $this->expectException(DestinationNotServiceableException::class);
        app(ShippingRateService::class)->quote($method->id, ['country' => 'PK'], 1000, [], 'USD');
    }

    public function test_inactive_method_is_not_serviceable(): void
    {
        [$zone, $method] = $this->zoneAndMethod(ShippingMethodType::FlatRate);
        $method->update(['is_active' => false]);
        \App\Domain\Shipping\Models\ShippingRate::factory()->for($zone, 'zone')->for($method, 'method')->create();

        $this->expectException(DestinationNotServiceableException::class);
        app(ShippingRateService::class)->quote($method->id, ['country' => 'PK'], 1000, [], 'USD');
    }

    /** @return array{0: ShippingZone, 1: ShippingMethod} */
    private function zoneAndMethod(ShippingMethodType $type): array
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $zone = ShippingZone::factory()->for($store)->create(['country' => 'PK']);
        $method = ShippingMethod::factory()->for($store)->create(['type' => $type]);

        return [$zone, $method];
    }
}
