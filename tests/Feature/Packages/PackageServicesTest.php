<?php

declare(strict_types=1);

namespace Tests\Feature\Packages;

use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Support\FeatureAvailability;
use App\Domain\Packages\Support\PackageServices;
use App\Domain\Packages\Support\PublicPlans;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1 (owner decision 2026-10-26): packages say what Umar Techy does for a
 * store (services), and a feature the platform does not have yet is never
 * shown or sold as included.
 */
final class PackageServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_standard_packages_get_their_services_and_re_seeding_keeps_edits(): void
    {
        $this->seed(PackageSeeder::class);

        foreach (PackageServices::DEFAULTS as $code => $services) {
            $this->assertSame($services, Package::query()->where('code', $code)->firstOrFail()->services);
        }

        Package::query()->where('code', 'business')->first()->update(['services' => ['Our own wording']]);
        $this->seed(PackageSeeder::class);
        $this->assertSame(['Our own wording'], Package::query()->where('code', 'business')->first()->services);
    }

    public function test_platform_staff_edit_services_and_bad_lines_are_refused(): void
    {
        $this->seed(PackageSeeder::class);
        $admin = User::factory()->create(['platform_role' => 'platform_admin']);

        $this->actingAs($admin)->putJson('/api/v1/super-admin/packages/premium', ['services' => ['Dedicated WhatsApp line', 'Monthly report']])
            ->assertOk()->assertJsonPath('data.services', ['Dedicated WhatsApp line', 'Monthly report']);

        $this->actingAs($admin)->putJson('/api/v1/super-admin/packages/premium', ['services' => ['Same', 'Same']])
            ->assertUnprocessable()->assertJsonValidationErrors('services.1');
        $this->actingAs($admin)->putJson('/api/v1/super-admin/packages/premium', ['services' => [str_repeat('x', 161)]])
            ->assertUnprocessable()->assertJsonValidationErrors('services.0');
        $this->actingAs($admin)->putJson('/api/v1/super-admin/packages/premium', ['services' => array_fill(0, 13, 'line')])
            ->assertUnprocessable()->assertJsonValidationErrors('services');

        // Leaving services out of an update keeps them.
        $this->actingAs($admin)->putJson('/api/v1/super-admin/packages/premium', ['name' => 'Premium'])->assertOk();
        $this->assertSame(['Dedicated WhatsApp line', 'Monthly report'], Package::query()->where('code', 'premium')->first()->services);
    }

    public function test_features_not_built_yet_are_marked_and_never_on_the_public_pricing(): void
    {
        $this->seed(PackageSeeder::class);
        $admin = User::factory()->create(['platform_role' => 'platform_admin']);

        $premium = collect($this->actingAs($admin)->getJson('/api/v1/super-admin/packages')->assertOk()->json('data'))->firstWhere('code', 'premium');
        $entitlements = collect($premium['entitlements'])->keyBy('key');
        $this->assertTrue($entitlements['ai.chatbot']['coming_soon']);
        $this->assertFalse($entitlements['loyalty.points']['coming_soon'], 'built in P2');
        $this->assertTrue($entitlements['advanced_analytics.enabled']['coming_soon']);
        $this->assertFalse($entitlements['products.variants']['coming_soon']);
        $this->assertSame(PackageServices::DEFAULTS['premium'], $premium['services']);

        foreach (PublicPlans::all() as $plan) {
            $this->assertSame([], array_values(array_intersect($plan['features'], FeatureAvailability::COMING_SOON)), "{$plan['code']} names a feature that is not built");
            $this->assertNotEmpty($plan['services']);
        }
    }
}
