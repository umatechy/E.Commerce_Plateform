<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Settings\Models\SettingRevision;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Settings\Services\Currencies;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Shipping\Models\ShippingRate;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Owner decision 2026-10-03: the platform is for Pakistan first. A store
 * prices in PKR (Rs.) unless it chooses another of the offered
 * currencies: PKR, USD, EUR, GBP, AED, SAR. Nothing is converted.
 */
final class CurrencyDefaultsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Store, 1: User} */
    private function shop(): array
    {
        $store = Store::factory()->create(['status' => 'active']);
        $this->entitle($store, ['orders.basic', 'payment.cod', 'shipping.basic', 'products.basic']);
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);
        app(TenantContext::class)->resolveToStore($store->id);

        return [$store, $owner];
    }

    public function test_a_new_store_prices_in_pkr_and_the_platform_offers_six_currencies(): void
    {
        [$store] = $this->shop();

        $this->assertSame('PKR', app(ConfigService::class)->get('store.default_currency'));
        $this->assertSame(['PKR', 'USD', 'EUR', 'GBP', 'AED', 'SAR'], app(ConfigService::class)->get('platform.supported_currencies'));
        // The free pickup rate every store starts with is in the store's currency, so carts find it.
        $this->assertSame(['PKR'], ShippingRate::query()->where('store_id', $store->id)->pluck('currency')->unique()->values()->all());
    }

    public function test_a_pkr_store_sells_end_to_end_in_rupees(): void
    {
        [$store, $owner] = $this->shop();

        // No currency given: the product takes the store's.
        $this->actingAs($owner)->postJson('/api/v1/products', ['name' => 'Rose Attar', 'type' => 'simple', 'status' => 'active', 'visibility' => 'public', 'price_minor' => 250000])
            ->assertCreated()->assertJsonPath('data.currency', 'PKR');
        $product = Product::query()->where('name', 'Rose Attar')->sole();
        $this->assertSame('PKR', $product->currency);

        $warehouse = Warehouse::query()->withoutTenantScope()->where('store_id', $store->id)->where('is_default', true)->firstOrFail();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['product_id' => $product->id]);
        app(InventoryService::class)->setOpeningStock($inventory, 5, 'Init', actorId: $owner->id, idempotencyKey: 'open-'.$store->id);
        $pickup = ShippingMethod::query()->where('store_id', $store->id)->where('type', 'store_pickup')->value('id');

        $this->app['auth']->forgetGuards();
        $add = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 2], ['X-Store-Slug' => $store->slug])->assertSuccessful();
        $add->assertJsonPath('data.currency', 'PKR');
        $token = (string) $add->headers->get('X-Guest-Cart-Token');

        // Before, guest carts started in a "USD" placeholder and a PKR store's rates were never offered.
        $quote = $this->getJson('/api/v1/shipping/quote?country=PK', ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token])->assertOk();
        $this->assertContains($pickup, collect($quote->json('data'))->pluck('shipping_method_id')->merge(collect($quote->json('data'))->pluck('id'))->all());
        $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod', 'shipping_method_id' => $pickup, 'shipping_address' => ['country' => 'PK'],
            'guest_name' => 'Ayesha', 'guest_email' => 'ayesha@example.com', 'idempotency_key' => 'pkr-checkout-1',
        ], ['X-Store-Slug' => $store->slug, 'X-Guest-Cart-Token' => $token])
            ->assertCreated()
            ->assertJsonPath('data.order.currency', 'PKR')
            ->assertJsonPath('data.order.grand_total_minor', 500000);
    }

    public function test_only_offered_currencies_are_accepted_and_codes_are_upper_cased(): void
    {
        [$store, $owner] = $this->shop();

        $this->actingAs($owner)->postJson('/api/v1/products', ['name' => 'Dollar item', 'type' => 'simple', 'currency' => 'usd', 'price_minor' => 1000])->assertCreated();
        $this->assertSame('USD', Product::query()->where('name', 'Dollar item')->value('currency'));
        $this->actingAs($owner)->postJson('/api/v1/products', ['name' => 'Loonie item', 'type' => 'simple', 'currency' => 'CAD', 'price_minor' => 1000])
            ->assertUnprocessable()->assertJsonValidationErrors('currency');

        $this->actingAs($owner)->putJson('/api/v1/store/settings/store.default_currency', ['value' => 'eur'])->assertOk();
        $this->assertSame('EUR', app(ConfigService::class)->get('store.default_currency'));
        $this->actingAs($owner)->putJson('/api/v1/store/settings/store.default_currency', ['value' => 'CAD'])->assertUnprocessable();

        $platform = User::factory()->create(['platform_role' => 'support_agent']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($platform)->putJson('/api/v1/super-admin/settings/platform.supported_currencies', ['value' => ['pkr', 'Rupees']])->assertUnprocessable();
        $this->actingAs($platform)->putJson('/api/v1/super-admin/settings/platform.supported_currencies', ['value' => ['pkr', 'usd', 'PKR', 'cad']])->assertOk();
        $this->assertSame(['PKR', 'USD', 'CAD'], app(ConfigService::class)->get('platform.supported_currencies'));
    }

    public function test_the_shell_offers_the_platform_currencies(): void
    {
        [, $owner] = $this->shop();

        $this->actingAs($owner)->withoutVite()->get('/')->assertOk()->assertInertia(fn ($page) => $page
            ->where('auth.currency', 'PKR')
            ->where('auth.currencies', Currencies::SUPPORTED));
    }

    public function test_existing_stores_keep_what_their_amounts_mean(): void
    {
        $priced = Store::factory()->create();
        $empty = Store::factory()->create();
        // Data as it was before the decision: USD everywhere by default.
        Product::factory()->for($priced)->create(['currency' => 'USD', 'price_minor' => 1000]);
        Product::factory()->for($priced)->create(['currency' => null]);
        ShippingRate::query()->withoutTenantScope()->whereIn('store_id', [$priced->id, $empty->id])->update(['currency' => 'USD']);
        DB::table('platform_settings')->insert(['key' => 'platform.supported_currencies', 'value' => json_encode([['USD', 'CAD']]), 'created_at' => now(), 'updated_at' => now()]);
        Cache::flush();

        (require base_path('database/migrations/2028_05_01_000005_pakistan_first_currency_defaults.php'))->up();

        // The platform list gains the six; a currency staff added stays.
        $this->assertSame(['PKR', 'USD', 'EUR', 'GBP', 'AED', 'SAR', 'CAD'], app(ConfigService::class)->get('platform.supported_currencies'));

        // A store with USD prices is pinned to USD, recorded in its settings history.
        app(TenantContext::class)->resolveToStore($priced->id);
        $this->assertSame('USD', app(ConfigService::class)->get('store.default_currency'));
        $this->assertSame(1, SettingRevision::query()->where('store_id', $priced->id)->where('key', 'store.default_currency')->count());
        $this->assertSame(0, Product::query()->withoutTenantScope()->where('store_id', $priced->id)->whereNull('currency')->count());
        $this->assertSame(['USD'], Product::query()->withoutTenantScope()->where('store_id', $priced->id)->pluck('currency')->unique()->values()->all());
        $this->assertSame('USD', ShippingRate::query()->withoutTenantScope()->where('store_id', $priced->id)->value('currency'), 'a pinned store keeps its rates');

        // A store without amounts moves to PKR, with its free pickup rate.
        app(TenantContext::class)->resolveToStore($empty->id);
        $this->assertSame('PKR', app(ConfigService::class)->get('store.default_currency'));
        $this->assertSame('PKR', ShippingRate::query()->withoutTenantScope()->where('store_id', $empty->id)->value('currency'));
    }
}
