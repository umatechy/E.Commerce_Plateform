<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Models\ContentTranslation;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase B38 — gap G11: SRS LOC-001–006, Module 05 §40–41, Module 06 §101,
 * Module 07 §99, Module 16 §27, Module 35 §4.3. Languages a store offers,
 * how a visit's language is chosen, translated content with fallback,
 * per-language addresses and caches, and Urdu validation messages.
 */
final class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private Product $product;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Store::factory()->create(['status' => 'active']);
        $this->entitle($this->store, ['orders.basic']);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role_id' => $this->systemRole($this->store, 'owner')->id, 'status' => 'active']);
        app(TenantContext::class)->resolveToStore($this->store->id);
        $this->category = Category::factory()->for($this->store)->create(['name' => 'Fragrances', 'status' => 'active', 'visibility' => 'public']);
        $this->product = Product::factory()->for($this->store)->create([
            'name' => 'Rose Attar', 'short_description' => 'A classic rose.', 'description' => 'Long lasting rose attar.',
            'status' => 'active', 'visibility' => 'public', 'price_minor' => 250000, 'primary_category_id' => $this->category->id,
        ]);
        $this->product->categories()->sync([$this->category->id]);
    }

    private function setting(string $key, mixed $value): void
    {
        DB::table('store_settings')->updateOrInsert(['store_id' => $this->store->id, 'key' => $key], ['value' => json_encode([$value]), 'created_at' => now(), 'updated_at' => now()]);
        Cache::flush();
    }

    private function offerUrdu(): void
    {
        $this->setting('store.languages', ['en', 'ur']);
    }

    private function page(string $query = '', array $cookies = []): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withoutVite()->withCookies($cookies)->get("/shop/{$this->store->slug}{$query}");
    }

    public function test_the_store_chooses_its_languages_and_the_default_must_be_one_of_them(): void
    {
        $put = fn (string $key, mixed $value) => $this->actingAs($this->owner)->putJson("/api/v1/store/settings/{$key}", ['value' => $value]);

        $put('store.languages', ['en', 'fr'])->assertStatus(422);
        $put('store.default_locale', 'ur')->assertStatus(422);       // not offered yet
        $put('store.languages', ['en', 'ur'])->assertOk();
        $put('store.default_locale', 'ur')->assertOk();
        $put('store.languages', ['en'])->assertStatus(422);           // would drop the default
        $put('store.default_locale', 'de')->assertStatus(422);
    }

    public function test_a_visit_is_in_the_default_language_unless_another_offered_one_is_chosen_and_remembered(): void
    {
        $this->page()->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('storefront.language.current', 'en')->where('storefront.language.dir', 'ltr')->has('storefront.language.offered', 1));
        // Urdu not offered: ?lang=ur is ignored.
        $this->page('?lang=ur')->assertInertia(fn (AssertableInertia $p) => $p->where('storefront.language.current', 'en'));

        $this->offerUrdu();
        $chosen = $this->page('?lang=ur')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('storefront.language.current', 'ur')->where('storefront.language.dir', 'rtl')->where('storefront.store.locale', 'ur')
            ->has('storefront.language.offered', 2)->where('storefront.language.offered.1.native', 'اردو'));
        $chosen->assertCookie('sf_lang', 'ur');
        $this->assertStringContainsString('dir="rtl"', $chosen->getContent());

        // The choice is remembered; the address can switch back.
        $this->page('', ['sf_lang' => 'ur'])->assertInertia(fn (AssertableInertia $p) => $p->where('storefront.language.current', 'ur'));
        $this->page('?lang=en', ['sf_lang' => 'ur'])->assertInertia(fn (AssertableInertia $p) => $p->where('storefront.language.current', 'en'));
    }

    public function test_translated_content_is_shown_in_its_language_with_the_original_as_fallback_and_separate_caches(): void
    {
        $this->offerUrdu();
        $this->actingAs($this->owner)->putJson("/api/v1/translations/product/{$this->product->public_id}", ['locale' => 'ur', 'fields' => ['name' => 'گلاب عطر']])
            ->assertOk()->assertJsonPath('data.translations.ur.name', 'گلاب عطر')->assertJsonPath('data.original.name', 'Rose Attar');
        $this->actingAs($this->owner)->putJson("/api/v1/translations/category/{$this->category->id}", ['locale' => 'ur', 'fields' => ['name' => 'خوشبوئیں']])->assertOk();

        // English first (fills the English cache), then Urdu: each sees its own.
        $this->getJson('/api/v1/storefront/products', ['X-Store-Slug' => $this->store->slug])->assertJsonPath('data.products.0.name', 'Rose Attar');
        $this->getJson('/api/v1/storefront/products', ['X-Store-Slug' => $this->store->slug, 'X-Storefront-Locale' => 'ur'])
            ->assertJsonPath('data.products.0.name', 'گلاب عطر')
            ->assertJsonPath('data.products.0.summary', 'A classic rose.');            // not translated: original
        $this->page('?lang=ur')->assertInertia(fn (AssertableInertia $p) => $p->where('storefront.navigation.categories.0.name', 'خوشبوئیں'));
        $this->page()->assertInertia(fn (AssertableInertia $p) => $p->where('storefront.navigation.categories.0.name', 'Fragrances'));

        $this->withoutVite()->get("/shop/{$this->store->slug}/products/{$this->product->slug}?lang=ur")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('product.name', 'گلاب عطر')->where('product.breadcrumbs.0.name', 'خوشبوئیں'));

        // Emptying a field removes its translation; the slug and SKU never change.
        $this->actingAs($this->owner)->putJson("/api/v1/translations/product/{$this->product->public_id}", ['locale' => 'ur', 'fields' => ['name' => '']])->assertOk();
        $this->assertSame(0, ContentTranslation::query()->where('translatable_type', 'product')->count());
        $this->getJson('/api/v1/storefront/products', ['X-Store-Slug' => $this->store->slug, 'X-Storefront-Locale' => 'ur'])->assertJsonPath('data.products.0.name', 'Rose Attar');
    }

    public function test_translations_need_the_items_permission_and_stay_in_their_store(): void
    {
        $staff = User::factory()->create();
        $this->store->users()->attach($staff, ['role_id' => $this->systemRole($this->store, 'staff')->id, 'status' => 'active']);
        $this->actingAs($staff)->putJson("/api/v1/translations/product/{$this->product->public_id}", ['locale' => 'ur', 'fields' => ['name' => 'x']])->assertForbidden();

        $other = Store::factory()->create();
        $foreign = Product::factory()->for($other)->create();
        $this->actingAs($this->owner)->getJson("/api/v1/translations/product/{$foreign->public_id}")->assertNotFound();
        $this->actingAs($this->owner)->putJson("/api/v1/translations/product/{$this->product->public_id}", ['locale' => 'xx', 'fields' => ['name' => 'x']])->assertStatus(422);
        $this->actingAs($this->owner)->putJson("/api/v1/translations/product/{$this->product->public_id}", ['locale' => 'ur', 'fields' => ['sku' => 'NEW']])->assertStatus(422);
        $this->actingAs($this->owner)->putJson("/api/v1/translations/product/{$this->product->public_id}", ['locale' => 'ur', 'fields' => ['name' => str_repeat('x', 256)]])->assertStatus(422);
        $this->actingAs($this->owner)->getJson('/api/v1/translations/order/1')->assertNotFound();
    }

    public function test_each_language_has_its_own_address_and_the_others_are_listed_for_search_engines(): void
    {
        $this->offerUrdu();
        $html = $this->page('?lang=ur')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<link rel="canonical" href="https://[^"?]+\?lang=ur"#', $html);
        $this->assertStringContainsString('hreflang="en"', $html);
        $this->assertStringContainsString('hreflang="ur"', $html);
        $this->assertStringContainsString('hreflang="x-default"', $html);
        $this->assertStringContainsString('content="ur_PK"', $html);

        $english = $this->page()->getContent();
        $this->assertDoesNotMatchRegularExpression('#rel="canonical" href="[^"]*lang=#', $english);
    }

    public function test_home_page_texts_and_the_tagline_follow_the_language(): void
    {
        $this->offerUrdu();
        $this->actingAs($this->owner)->putJson('/api/v1/store/theme/draft', ['config' => [
            'branding' => ['tagline' => 'Fine fragrances', 'translations' => ['ur' => ['tagline' => 'عمدہ خوشبوئیں']]],
            'sections' => [['type' => 'hero', 'config' => ['heading' => 'Welcome', 'cta_url' => 'https://example.com/sale', 'translations' => ['ur' => ['heading' => 'خوش آمدید']]]]],
        ]])->assertOk();
        $this->actingAs($this->owner)->putJson('/api/v1/store/theme/draft', ['config' => ['sections' => [['type' => 'hero', 'config' => ['translations' => ['fr' => ['heading' => 'x']]]]]]])->assertStatus(422);
        $this->actingAs($this->owner)->putJson('/api/v1/store/theme/draft', ['config' => ['sections' => [['type' => 'hero', 'config' => ['translations' => ['ur' => ['cta_url' => 'https://x.example']]]]]]])->assertStatus(422);
        $this->actingAs($this->owner)->putJson('/api/v1/store/theme/draft', ['config' => [
            'branding' => ['tagline' => 'Fine fragrances', 'translations' => ['ur' => ['tagline' => 'عمدہ خوشبوئیں']]],
            'sections' => [['type' => 'hero', 'config' => ['heading' => 'Welcome', 'cta_url' => 'https://example.com/sale', 'translations' => ['ur' => ['heading' => 'خوش آمدید']]]]],
        ]])->assertOk();
        $this->actingAs($this->owner)->postJson('/api/v1/store/theme/publish')->assertOk();

        $this->page('?lang=ur')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('storefront.store.tagline', 'عمدہ خوشبوئیں')
            ->where('sections.0.heading', 'خوش آمدید')->where('sections.0.cta_url', 'https://example.com/sale')->missing('sections.0.translations'));
        $this->page()->assertInertia(fn (AssertableInertia $p) => $p->where('storefront.store.tagline', 'Fine fragrances')->where('sections.0.heading', 'Welcome'));
    }

    public function test_api_answers_and_cart_lines_come_in_the_shoppers_language(): void
    {
        $this->offerUrdu();
        $headers = ['X-Store-Slug' => $this->store->slug, 'X-Storefront-Locale' => 'ur'];

        $this->postJson('/api/v1/storefront/session/register', [], $headers)->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'ای میل ضروری ہے۔');
        // A language the store does not offer is not used.
        $this->postJson('/api/v1/storefront/session/register', [], [...$headers, 'X-Storefront-Locale' => 'de'])->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'The email field is required.');

        $this->actingAs($this->owner)->putJson("/api/v1/translations/product/{$this->product->public_id}", ['locale' => 'ur', 'fields' => ['name' => 'گلاب عطر']])->assertOk();
        $this->app['auth']->forgetGuards();
        $cart = $this->postJson('/api/v1/cart/items', ['product' => $this->product->public_id, 'quantity' => 1], $headers)->assertSuccessful();
        $token = $cart->json('data.guest_token') ?? $cart->headers->get('X-Guest-Cart-Token');
        $lines = $this->getJson('/api/v1/cart', [...$headers, 'X-Guest-Cart-Token' => (string) $token])->json('data.items');
        $this->assertSame('گلاب عطر', $lines[0]['product_name'] ?? $cart->json('data.items.0.product_name'));
    }
}
