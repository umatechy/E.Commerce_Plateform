<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Domain\Billing\Models\PackagePrice;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Settings\Exceptions\InvalidSettingValueException;
use App\Domain\Settings\Services\SettingRegistry;
use App\Domain\Settings\Services\SettingValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * UI-1: the platform's name and logo come from platform settings, and the
 * public home page shows the packages as priced, naming only built features.
 */
final class BrandingAndHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_shows_priced_packages_and_never_promises_unbuilt_features(): void
    {
        $package = Package::factory()->create(['name' => 'Business', 'code' => 'business']);
        PackagePrice::query()->create(['package_id' => $package->id, 'billing_interval' => 'monthly', 'currency' => 'PKR', 'amount_minor' => 450000]);
        foreach (['payment.cod' => true, 'ai.chatbot' => true, 'affiliate.programs' => false] as $key => $on) {
            $package->entitlements()->create(['key' => $key, 'type' => EntitlementType::Feature, 'boolean_value' => $on]);
        }
        $package->entitlements()->create(['key' => 'max_products', 'type' => EntitlementType::UsageLimit, 'limit_value' => 500, 'is_unlimited' => false]);

        $this->withoutVite()->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Welcome')
            ->where('plans.0.name', 'Business')
            ->where('plans.0.price.amount_minor', 450000)
            ->where('plans.0.price.currency', 'PKR')
            ->where('plans.0.features', ['payment.cod'])
            ->where('plans.0.limits.max_products', 500)
            ->where('brand.name', 'Umar Techy')
            ->where('brand.logo_url', null));
    }

    public function test_the_name_and_logo_are_platform_settings_and_a_logo_must_be_a_picture_address(): void
    {
        $validator = app(SettingValidator::class);
        $logo = SettingRegistry::all()['platform.logo_url'];
        $this->assertSame('/brand/logo.svg', $validator->validate($logo, '/brand/logo.svg'));
        $this->assertSame('https://cdn.example.com/umar-techy.png', $validator->validate($logo, 'https://cdn.example.com/umar-techy.png'));
        foreach (['http://cdn.example.com/logo.png', '//evil.example/logo.png', 'javascript:alert(1)', '/brand/logo.html', 'https://cdn.example.com/page'] as $bad) {
            try {
                $validator->validate($logo, $bad);
                $this->fail("Accepted {$bad}");
            } catch (InvalidSettingValueException) {
                $this->addToAssertionCount(1);
            }
        }

        DB::table('platform_settings')->insert([
            ['key' => 'platform.brand_name', 'value' => json_encode(['Umar Techy Store Builder']), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'platform.logo_url', 'value' => json_encode(['/brand/logo.svg']), 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->withoutVite()->get('/login')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('brand.name', 'Umar Techy Store Builder')
            ->where('brand.logo_url', '/brand/logo.svg'));
    }
}
