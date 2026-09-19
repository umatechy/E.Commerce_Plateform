<?php

declare(strict_types=1);

namespace Tests\Feature\Packages;

use App\Domain\Packages\Models\Package;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * This milestone's "B2 Tests / PACKAGE" section.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class PackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_package_listing_returns_only_active_packages(): void
    {
        Package::factory()->create(['code' => 'active-one', 'is_active' => true]);
        Package::factory()->create(['code' => 'inactive-one', 'is_active' => false]);

        $response = $this->getJson('/api/v1/public/packages');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('active-one'));
        $this->assertFalse($codes->contains('inactive-one'));
    }

    public function test_package_listing_is_public_and_requires_no_authentication(): void
    {
        Package::factory()->create();

        $this->getJson('/api/v1/public/packages')->assertOk();
    }

    public function test_package_resource_never_exposes_pricing_or_billing_fields(): void
    {
        Package::factory()->create(['code' => 'basic-test']);

        $response = $this->getJson('/api/v1/public/packages');

        $response->assertOk();
        $response->assertJsonMissingPath('data.0.price');
        $response->assertJsonMissingPath('data.0.billing');
    }

    public function test_package_includes_its_entitlements(): void
    {
        $package = Package::factory()->create();
        $package->entitlements()->create([
            'key' => 'test.feature',
            'type' => \App\Domain\Packages\Models\EntitlementType::Feature,
            'boolean_value' => true,
        ]);

        $response = $this->getJson('/api/v1/public/packages');

        $response->assertOk();
        $response->assertJsonFragment(['key' => 'test.feature', 'enabled' => true]);
    }
}
