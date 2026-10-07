<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use App\Domain\Catalog\Models\Product;
use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Storefront\Services\StorefrontCache;
use App\Domain\Theme\Models\StoreTheme;
use App\Domain\Theme\Models\Theme;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase B36 — Module 17 (theme library, selection, layout, sections,
 * inheritance, package entitlements) and Module 18 (motion profiles) on the
 * server: what each package may choose, what is refused, and what a store
 * that lost a feature is shown.
 */
final class ThemeLibraryTest extends TestCase
{
    use RefreshDatabase;

    private const BASIC = ['orders.basic'];
    private const BUSINESS = ['orders.basic', 'themes.business', 'homepage.advanced_sections', 'animation.advanced'];
    private const PREMIUM = ['orders.basic', 'themes.business', 'themes.premium', 'layout.advanced', 'homepage.advanced_sections', 'homepage.reorder', 'animation.advanced', 'animation.premium'];

    /** @param list<string> $features */
    private function store(array $features): array
    {
        $store = Store::factory()->create(['status' => 'active']);
        $this->entitle($store, $features);
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);

        return [$store, $owner];
    }

    private function storeTheme(Store $store): StoreTheme
    {
        return StoreTheme::query()->withoutTenantScope()->where('store_id', $store->id)->firstOrFail();
    }

    /** The storefront shell and home sections customers get. */
    private function storefront(Store $store, callable $assert): void
    {
        $this->app['auth']->forgetGuards();
        $this->withoutVite()->get("/shop/{$store->slug}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $assert($page));
    }

    public function test_the_library_lists_the_five_themes_and_what_each_package_includes(): void
    {
        foreach (['basic' => [self::BASIC, ['default', 'minimal']], 'business' => [self::BUSINESS, ['default', 'minimal', 'modern']], 'premium' => [self::PREMIUM, ['default', 'minimal', 'modern', 'boutique', 'bold']]] as $label => [$features, $included]) {
            [, $owner] = $this->store($features);
            $themes = collect($this->actingAs($owner)->getJson('/api/v1/store/themes')->assertOk()->json('data'));

            $this->assertSame(['default', 'minimal', 'modern', 'boutique', 'bold'], $themes->pluck('key')->all(), $label);
            $this->assertSame($included, $themes->where('included', true)->pluck('key')->values()->all(), $label);
            $this->assertSame('Classic', $themes->firstWhere('key', 'default')['name']);
            $this->assertTrue($themes->firstWhere('key', 'default')['published']);
            $this->assertSame('Poppins', $themes->firstWhere('key', 'boutique')['tokens']['heading_font']);
        }
    }

    public function test_a_theme_outside_the_package_is_refused_and_one_inside_goes_to_the_draft_keeping_branding_and_sections(): void
    {
        [$basic, $basicOwner] = $this->store(self::BASIC);
        $this->actingAs($basicOwner)->postJson('/api/v1/store/theme/select', ['theme' => 'boutique'])
            ->assertForbidden()->assertJsonPath('code', 'feature_not_entitled');
        $this->actingAs($basicOwner)->postJson('/api/v1/store/theme/select', ['theme' => 'no-such-theme'])->assertStatus(422);
        $this->assertNotSame('boutique', $this->storeTheme($basic)->draft_config['theme'] ?? null);

        [$store, $owner] = $this->store(self::PREMIUM);
        $this->actingAs($owner)->putJson('/api/v1/store/theme/draft', ['config' => [
            'tokens' => ['primary' => '#123456'],
            'branding' => ['tagline' => 'Ours'],
            'sections' => [['type' => 'hero', 'config' => ['heading' => 'Hello']]],
        ]])->assertOk();

        $this->actingAs($owner)->postJson('/api/v1/store/theme/select', ['theme' => 'boutique'])->assertOk()
            ->assertJsonPath('data.draft_config.theme', 'boutique')
            ->assertJsonPath('data.draft_config.tokens', [])          // the theme's own colours again
            ->assertJsonPath('data.draft_config.branding.tagline', 'Ours')
            ->assertJsonPath('data.draft_config.sections.0.config.heading', 'Hello');
        // Not live until published.
        $this->assertSame('default', $this->storeTheme($store)->theme->key);

        $this->actingAs($owner)->postJson('/api/v1/store/theme/publish')->assertOk();
        $this->assertSame('boutique', $this->storeTheme($store)->fresh()->theme->key);
        $this->storefront($store, fn (AssertableInertia $page) => $page
            ->where('storefront.theme.key', 'boutique')
            ->where('storefront.theme.tokens.heading_font', 'Poppins')
            ->where('storefront.theme.layout.header_style', 'centered')
            ->where('storefront.theme.motion.profile', 'premium'));

        $this->assertTrue(AuditLog::query()->where('action', 'theme.selected')->where('store_id', $store->id)->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'theme.published')->where('store_id', $store->id)->exists());
        // Another store is untouched.
        $this->assertSame('default', $this->storeTheme($basic)->fresh()->theme->key);
    }

    public function test_premium_layouts_motion_and_advanced_sections_need_their_package(): void
    {
        [, $owner] = $this->store(self::BASIC);
        $put = fn (array $config) => $this->actingAs($owner)->putJson('/api/v1/store/theme/draft', ['config' => $config]);

        $put(['layout' => ['header_style' => 'centered']])->assertForbidden()->assertJsonPath('code', 'feature_not_entitled');
        $put(['layout' => ['product_card' => 'overlay']])->assertForbidden();
        $put(['motion' => ['profile' => 'standard']])->assertForbidden();
        $put(['motion' => ['profile' => 'minimal', 'intensity' => 'high']])->assertForbidden();
        $put(['motion' => ['reveal_on_scroll' => true]])->assertForbidden();
        $put(['sections' => [['type' => 'testimonials', 'config' => ['items' => [['quote' => 'Good', 'name' => 'A']]]]]])
            ->assertForbidden()->assertJsonFragment(['violations' => ['The home page section "testimonials" is not included in your package.']]);

        // Owner decision 14: serif fonts are no longer offered.
        $put(['tokens' => ['heading_font' => 'Playfair Display']])->assertStatus(422);
        // What Basic includes is accepted.
        $put(['theme' => 'minimal', 'layout' => ['header_style' => 'minimal', 'grid_columns' => 3, 'sticky_header' => true], 'motion' => ['profile' => 'none', 'hover_effects' => false], 'tokens' => ['radius' => 'none', 'heading_font' => 'Montserrat', 'shadow' => 'strong', 'density' => 'compact']])
            ->assertOk()->assertJsonPath('data.draft_config.layout.grid_columns', 3);

        [, $business] = $this->store(self::BUSINESS);
        $this->actingAs($business)->putJson('/api/v1/store/theme/draft', ['config' => ['theme' => 'modern', 'motion' => ['profile' => 'standard', 'reveal_on_scroll' => true], 'sections' => [['type' => 'faq', 'config' => ['items' => [['question' => 'Q?', 'answer' => 'A.']]]]]]])->assertOk();
        $this->actingAs($business)->putJson('/api/v1/store/theme/draft', ['config' => ['motion' => ['profile' => 'playful']]])->assertForbidden();
    }

    public function test_sections_keep_the_fixed_order_unless_the_package_includes_reordering(): void
    {
        $reversed = [
            ['type' => 'featured_products', 'position' => 0, 'config' => []],
            ['type' => 'featured_categories', 'position' => 1, 'config' => []],
            ['type' => 'hero', 'position' => 2, 'config' => ['heading' => 'Top']],
        ];

        [, $basic] = $this->store(self::BASIC);
        $saved = $this->actingAs($basic)->putJson('/api/v1/store/theme/draft', ['config' => ['sections' => $reversed]])->assertOk()->json('data.draft_config.sections');
        $this->assertSame(['hero', 'featured_categories', 'featured_products'], array_column($saved, 'type'));
        $this->assertSame([0, 1, 2], array_column($saved, 'position'));

        [, $premium] = $this->store(self::PREMIUM);
        $saved = $this->actingAs($premium)->putJson('/api/v1/store/theme/draft', ['config' => ['sections' => $reversed]])->assertOk()->json('data.draft_config.sections');
        $this->assertSame(['featured_products', 'featured_categories', 'hero'], array_column($saved, 'type'));
    }

    public function test_a_store_that_loses_its_package_is_shown_the_nearest_included_presentation_without_losing_its_settings(): void
    {
        [$store, $owner] = $this->store(self::PREMIUM);
        $this->actingAs($owner)->putJson('/api/v1/store/theme/draft', ['config' => [
            'theme' => 'bold',
            'motion' => ['profile' => 'playful', 'intensity' => 'high'],
            'sections' => [
                ['type' => 'rich_text', 'position' => 0, 'config' => ['heading' => 'About', 'text' => 'Story']],
                ['type' => 'hero', 'position' => 1, 'config' => ['heading' => 'Sale']],
            ],
        ]])->assertOk();
        $this->actingAs($owner)->postJson('/api/v1/store/theme/publish')->assertOk();
        $this->storefront($store, fn (AssertableInertia $page) => $page
            ->where('storefront.theme.key', 'bold')->where('storefront.theme.layout.header_style', 'split')->where('storefront.theme.motion.profile', 'playful')
            ->where('sections.0.type', 'rich_text')->where('sections.1.type', 'hero'));

        // Downgraded to Basic: the subscription change also clears the storefront cache.
        $version = app(StorefrontCache::class)->version($store->id);
        $basic = Package::factory()->create();
        $basic->entitlements()->create(['key' => 'orders.basic', 'type' => 'feature', 'boolean_value' => true]);
        Subscription::query()->withoutTenantScope()->where('store_id', $store->id)->firstOrFail()->update(['package_id' => $basic->id]);
        $this->assertGreaterThan($version, app(StorefrontCache::class)->version($store->id));
        Cache::flush(); // entitlements are cached per request elsewhere

        $this->storefront($store, fn (AssertableInertia $page) => $page
            ->where('storefront.theme.key', 'default')
            ->where('storefront.theme.layout.header_style', 'classic')
            ->where('storefront.theme.motion.profile', 'minimal')
            ->where('storefront.theme.motion.intensity', 'medium')
            ->has('sections', 1)->where('sections.0.type', 'hero'));
        // The configuration itself is kept.
        $this->assertSame('bold', $this->storeTheme($store)->fresh()->published_config['theme']);

        // Publishing it again, or going back to it, is refused until the package includes it.
        $this->actingAs($owner)->postJson('/api/v1/store/theme/publish')->assertForbidden();
    }

    public function test_the_advanced_sections_show_store_data(): void
    {
        [$store, $owner] = $this->store(self::PREMIUM);
        app(\App\Domain\Tenancy\Support\TenantContext::class)->resolveToStore($store->id);
        $plain = Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public', 'price_minor' => 10000, 'sale_price_minor' => null, 'name' => 'Plain']);
        $onSale = Product::factory()->for($store)->create(['status' => 'active', 'visibility' => 'public', 'price_minor' => 10000, 'sale_price_minor' => 8000, 'name' => 'Cheaper']);

        $this->actingAs($owner)->putJson('/api/v1/store/theme/draft', ['config' => ['sections' => [
            ['type' => 'sale_products', 'position' => 0, 'config' => ['heading' => 'Deals']],
            ['type' => 'best_sellers', 'position' => 1, 'config' => []],
            ['type' => 'trust_badges', 'position' => 2, 'config' => ['items' => [['icon' => 'cod', 'title' => 'Cash on delivery']]]],
        ]]])->assertOk();
        $this->actingAs($owner)->postJson('/api/v1/store/theme/publish')->assertOk();

        $this->storefront($store, fn (AssertableInertia $page) => $page
            ->where('sections.0.type', 'sale_products')->where('sections.0.heading', 'Deals')
            ->has('sections.0.products', 1)->where('sections.0.products.0.name', 'Cheaper')
            ->where('sections.1.type', 'best_sellers')->has('sections.1.products', 0)   // no sales yet: none
            ->where('sections.2.items.0.icon', 'cod'));

        // After sales, best sellers are the products sold most (cancelled orders do not count).
        $sell = function (Product $product, int $units, string $status) use ($store) {
            $order = \App\Domain\Orders\Models\Order::factory()->for($store)->create(['status' => $status]);
            \App\Domain\Orders\Models\OrderItem::factory()->create(['store_id' => $store->id, 'order_id' => $order->id, 'product_id' => $product->id, 'quantity' => $units]);
        };
        $sell($plain, 3, 'confirmed');
        $sell($onSale, 1, 'delivered');
        $sell($onSale, 10, 'cancelled');
        app(StorefrontCache::class)->bump($store->id);
        $this->storefront($store, fn (AssertableInertia $page) => $page
            ->has('sections.1.products', 2)->where('sections.1.products.0.name', 'Plain')->where('sections.1.products.1.name', 'Cheaper'));
    }

    public function test_the_configuration_schema_refuses_what_it_does_not_know(): void
    {
        [, $owner] = $this->store(self::PREMIUM);
        $put = fn (array $config) => $this->actingAs($owner)->putJson('/api/v1/store/theme/draft', ['config' => $config]);

        $put(['theme' => 'unknown'])->assertStatus(422);
        $put(['tokens' => ['heading_font' => 'Comic Sans']])->assertStatus(422);
        $put(['tokens' => ['font_family' => 'https://evil.example/font.css']])->assertStatus(422);
        $put(['layout' => ['header_style' => 'sideways']])->assertStatus(422);
        $put(['layout' => ['grid_columns' => 7]])->assertStatus(422);
        $put(['layout' => ['sticky_header' => 'yes']])->assertStatus(422);
        $put(['layout' => ['script' => 'x']])->assertStatus(422);
        $put(['motion' => ['profile' => 'custom']])->assertStatus(422);
        $put(['sections' => [['type' => 'trust_badges', 'config' => ['items' => [['icon' => 'javascript:alert(1)', 'title' => 'X']]]]]])->assertStatus(422);
        $put(['sections' => [['type' => 'faq', 'config' => ['items' => array_fill(0, 13, ['question' => 'Q', 'answer' => 'A'])]]]])->assertStatus(422);
        $put(['sections' => [['type' => 'testimonials', 'config' => ['items' => [['quote' => 'No name']]]]]])->assertStatus(422);
        $put(['sections' => array_fill(0, 31, ['type' => 'hero', 'config' => []])])->assertStatus(422);
        // Text is stored as text; the storefront escapes it when it renders.
        $put(['sections' => [['type' => 'rich_text', 'config' => ['text' => '<script>alert(1)</script>']]]])->assertOk()
            ->assertJsonPath('data.draft_config.sections.0.config.text', '<script>alert(1)</script>');
    }

    public function test_the_registry_and_the_packages_have_the_theme_features(): void
    {
        $this->assertSame(['default', 'minimal', 'modern', 'boutique', 'bold'], Theme::query()->orderBy('sort_order')->pluck('key')->all());
        $this->seed(\Database\Seeders\PackageSeeder::class);
        $features = fn (string $code) => Package::query()->where('code', $code)->firstOrFail()->entitlements()->where('boolean_value', true)->pluck('key')->all();
        $this->assertEmpty(array_intersect(['themes.business', 'themes.premium', 'layout.advanced', 'homepage.reorder', 'animation.premium'], $features('basic')));
        $this->assertEqualsCanonicalizing(['themes.business', 'homepage.advanced_sections', 'animation.advanced'], array_values(array_intersect(['themes.business', 'themes.premium', 'layout.advanced', 'homepage.advanced_sections', 'homepage.reorder', 'animation.advanced', 'animation.premium'], $features('business'))));
        $this->assertEmpty(array_diff(['themes.business', 'themes.premium', 'layout.advanced', 'homepage.advanced_sections', 'homepage.reorder', 'animation.advanced', 'animation.premium'], $features('premium')));
    }
}
