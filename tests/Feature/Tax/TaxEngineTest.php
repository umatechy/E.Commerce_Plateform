<?php

declare(strict_types=1);

namespace Tests\Feature\Tax;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Promotions\Models\Promotion;
use App\Domain\Returns\Services\RefundCalculator;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Shipping\Models\ShippingRate;
use App\Domain\Shipping\Models\ShippingZone;
use App\Domain\Tax\Models\TaxClass;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase B46 — gap G3, owner decision 1 (Module 11 §28, Module 05 §43, §75,
 * Module 33 §29, Module 13 §62, Module 14 §36, Module 09 §10–12, Module 29
 * §57–58, §95–96; SRS CHK-007): the store's own tax rules, server-side, on
 * every order, kept on the order as they were.
 */
final class TaxEngineTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private Product $product;

    private int $methodId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->store = Store::factory()->create(['status' => 'active']);
        $this->storeCurrency($this->store, 'PKR');
        $package = Package::factory()->create();
        foreach (['orders.basic', 'payment.cod', 'shipping.basic', 'products.basic'] as $feature) {
            $package->entitlements()->create(['key' => $feature, 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        }
        Subscription::factory()->for($this->store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role_id' => $this->systemRole($this->store, 'owner')->id, 'status' => 'active']);
        $this->product = $this->product('Lawn suit', 10000);
        app(TenantContext::class)->resolveToStore($this->store->id);
        $zone = ShippingZone::factory()->for($this->store)->create(['country' => 'PK']);
        $method = ShippingMethod::factory()->for($this->store)->create();
        ShippingRate::factory()->for($zone, 'zone')->for($method, 'method')->create(['base_cost_minor' => 500, 'currency' => 'PKR']);
        $this->methodId = $method->id;
    }

    private function product(string $name, int $price, array $extra = []): Product
    {
        $product = Product::factory()->for($this->store)->create(['name' => $name, 'status' => 'active', 'visibility' => 'public', 'price_minor' => $price, 'currency' => 'PKR', ...$extra]);
        $warehouse = Warehouse::query()->withoutTenantScope()->where('store_id', $this->store->id)->where('is_default', true)->firstOrFail();
        $inventory = Inventory::factory()->for($this->store)->for($warehouse)->create(['product_id' => $product->id]);
        app(TenantContext::class)->resolveToStore($this->store->id);
        app(InventoryService::class)->setOpeningStock($inventory, 50, 'Init', actorId: $this->actorId(), idempotencyKey: 'open-'.$product->id);

        return $product;
    }

    private function setting(string $key, mixed $value): void
    {
        DB::table('store_settings')->updateOrInsert(['store_id' => $this->store->id, 'key' => $key], ['value' => json_encode([$value]), 'created_at' => now(), 'updated_at' => now()]);
        Cache::flush();
    }

    /** A default "Standard" class with the national rate and a provincial one, tax on. */
    private function pakistanLikeSetUp(): TaxClass
    {
        $standard = TaxClass::query()->create(['name' => 'Standard', 'is_default' => true]);
        TaxRate::query()->create(['tax_class_id' => $standard->id, 'name' => 'Sales tax', 'country' => 'PK', 'rate_bps' => 1700]);
        TaxRate::query()->create(['tax_class_id' => $standard->id, 'name' => 'Provincial', 'country' => 'PK', 'region' => 'Punjab', 'rate_bps' => 100]);
        $this->setting('tax.enabled', true);

        return $standard;
    }

    /** @return array<string, string> headers of a guest cart holding the products */
    private function cart(array $lines): array
    {
        $token = null;
        foreach ($lines as [$product, $quantity]) {
            $response = $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => $quantity], array_filter(['X-Store-Slug' => $this->store->slug, 'X-Guest-Cart-Token' => $token]));
            $token ??= $response->headers->get('X-Guest-Cart-Token');
        }

        return ['X-Store-Slug' => $this->store->slug, 'X-Guest-Cart-Token' => (string) $token];
    }

    private function checkout(array $headers, string $key, array $address = ['country' => 'PK']): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/checkout', [
            'payment_method' => 'cod', 'shipping_method_id' => $this->methodId, 'shipping_address' => $address,
            'guest_name' => 'Sana', 'guest_email' => 'sana@example.com', 'idempotency_key' => $key,
        ], $headers);
    }

    public function test_tax_stays_off_and_zero_until_the_store_sets_it_up(): void
    {
        $order = $this->checkout($this->cart([[$this->product, 1]]), 'tax-off')->assertCreated()->json('data.order');
        $this->assertSame([0, 10500, null], [$order['tax_total_minor'], $order['grand_total_minor'], $order['tax']]);

        // No rate is built in: turning tax on needs one first.
        TaxClass::query()->create(['name' => 'Standard', 'is_default' => true]);
        $this->actingAs($this->owner)->putJson('/api/v1/tax/settings', ['enabled' => true])->assertStatus(422)->assertJsonValidationErrors('enabled');
        $this->assertSame(0, TaxRate::query()->count());
    }

    public function test_exclusive_tax_is_shown_before_ordering_added_on_checkout_and_kept_on_the_order(): void
    {
        $this->pakistanLikeSetUp();
        $this->setting('tax.shipping_taxable', true);
        Promotion::factory()->for($this->store)->create(['percentage_value' => 10]);
        $headers = $this->cart([[$this->product, 1]]);
        $address = ['country' => 'PK', 'province' => 'punjab'];

        // Base 10 000 − 10 % = 9 000: 17 % + 1 % = 1 620; shipping 500 × 18 % = 90.
        $summary = $this->postJson('/api/v1/checkout/summary', ['shipping_method_id' => $this->methodId, 'shipping_address' => $address], $headers)->assertOk()->json('data');
        $this->assertSame([10000, 1000, 500, 1710, 90, 11210], [$summary['subtotal_minor'], $summary['discount_minor'], $summary['shipping_minor'], $summary['tax']['total_minor'], $summary['tax']['shipping_minor'], $summary['grand_total_minor']]);
        $this->assertSame(['Sales tax', 'Provincial'], array_column($summary['tax']['breakdown'], 'name'));
        $before = $this->postJson('/api/v1/checkout/summary', ['shipping_address' => $address], $headers)->assertOk()->json('data');
        $this->assertSame([true, null], [$before['needs_shipping_method'], $before['shipping_minor']]);

        $order = $this->checkout($headers, 'tax-exclusive', $address)->assertCreated()->json('data.order');
        $this->assertSame([1710, 11210, false], [$order['tax_total_minor'], $order['grand_total_minor'], $order['tax']['prices_include_tax']]);
        $this->assertSame(1620, $order['items'][0]['tax_minor']);
        $this->assertSame(90, $order['tax']['shipping_tax_minor']);
        $this->assertSame([['Sales tax', 1700, 9500, 1615], ['Provincial', 100, 9500, 95]], array_map(fn ($b) => [$b['name'], $b['rate_bps'], $b['base_minor'], $b['tax_minor']], $order['tax']['breakdown']));

        // Later changes to the rates never rewrite the order (Module 29 §58).
        TaxRate::query()->update(['rate_bps' => 2500]);
        $stored = Order::query()->where('order_number', $order['order_number'])->firstOrFail();
        $this->assertSame([1710, 11210, 1], [(int) $stored->tax_total_minor, (int) $stored->grand_total_minor, $stored->tax_snapshot['policy_version']]);

        // Outside Punjab only the national rate applies.
        $other = $this->checkout($this->cart([[$this->product, 1]]), 'tax-sindh', ['country' => 'PK', 'province' => 'Sindh'])->assertCreated()->json('data.order');
        $this->assertSame(2250 + 125, $other['tax_total_minor']); // rates are now 25 %: 9 000 and 500
    }

    public function test_inclusive_prices_already_hold_the_tax_and_refunds_do_not_add_it_again(): void
    {
        $this->pakistanLikeSetUp();
        $this->setting('tax.prices_include_tax', true);
        $product = $this->product('Inclusive kurta', 11700);

        $order = $this->checkout($this->cart([[$product, 1]]), 'tax-inclusive')->assertCreated()->json('data.order');
        // 11 700 already holds 17 %: 10 000 net + 1 700 tax; the total stays the price plus shipping.
        $this->assertSame([1700, 12200, true], [$order['tax_total_minor'], $order['grand_total_minor'], $order['tax']['prices_include_tax']]);

        $stored = Order::query()->where('order_number', $order['order_number'])->with('items')->firstOrFail();
        $refund = app(RefundCalculator::class)->forItems($stored, [$stored->items[0]->id => 1]);
        $this->assertSame(11700, $refund[$stored->items[0]->id], 'the price paid, not the price plus its tax again');
    }

    public function test_classes_dates_and_exempt_customers_decide_what_is_taxed(): void
    {
        $standard = $this->pakistanLikeSetUp();
        $zero = TaxClass::query()->create(['name' => 'Zero rated']);
        $book = $this->product('Quran', 5000, ['tax_class_id' => $zero->id]);
        TaxRate::query()->create(['tax_class_id' => $standard->id, 'name' => 'Old levy', 'country' => 'PK', 'rate_bps' => 500, 'ends_on' => now()->subDay()->toDateString()]);
        TaxRate::query()->create(['tax_class_id' => $standard->id, 'name' => 'Paused', 'country' => 'PK', 'rate_bps' => 500, 'is_active' => false]);

        $order = $this->checkout($this->cart([[$this->product, 1], [$book, 2]]), 'tax-classes')->assertCreated()->json('data.order');
        $this->assertSame(1700, $order['tax_total_minor'], 'the zero-rated book pays nothing; old and paused rates do not apply');

        // A customer the store marked exempt: no tax, and the order says why.
        $customer = Customer::query()->create(['name' => 'Al-Noor Trust', 'email' => 'trust@example.com']);
        $this->actingAs($this->owner)->putJson("/api/v1/customers/{$customer->public_id}/tax-exemption", ['tax_exempt' => true])->assertStatus(422);
        $this->actingAs($this->owner)->putJson("/api/v1/customers/{$customer->public_id}/tax-exemption", ['tax_exempt' => true, 'tax_exemption_reference' => 'NTN 1234567-8'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'tax.customer_exemption_updated']);
        $staffOrder = $this->actingAs($this->owner)->postJson('/api/v1/orders', [
            'customer' => $customer->public_id, 'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
            'shipping_address' => ['country' => 'PK'], 'idempotency_key' => 'tax-exempt-1',
        ])->assertCreated()->json('data');
        $this->assertSame([0, 10000, true, 'NTN 1234567-8'], [$staffOrder['tax_total_minor'], $staffOrder['grand_total_minor'], $staffOrder['tax']['exempt'], $staffOrder['tax']['exemption_reference']]);
    }

    public function test_the_tax_set_up_is_managed_by_the_right_people_validated_and_audited(): void
    {
        $manager = User::factory()->create();
        $this->store->users()->attach($manager, ['role_id' => $this->systemRole($this->store, 'manager')->id, 'status' => 'active']);
        $this->actingAs($manager)->getJson('/api/v1/tax')->assertOk();
        $this->actingAs($manager)->postJson('/api/v1/tax/classes', ['name' => 'Standard'])->assertForbidden();
        $this->app['auth']->forgetGuards();

        // The first class becomes the default; there is always one default.
        $standard = $this->actingAs($this->owner)->postJson('/api/v1/tax/classes', ['name' => 'Standard'])->assertCreated()->json('data');
        $this->assertTrue($standard['is_default']);
        $reduced = $this->actingAs($this->owner)->postJson('/api/v1/tax/classes', ['name' => 'Reduced', 'is_default' => true])->assertCreated()->json('data');
        $this->assertFalse(TaxClass::query()->findOrFail($standard['id'])->is_default);
        $this->actingAs($this->owner)->postJson('/api/v1/tax/classes', ['name' => 'reduced'])->assertStatus(422);

        $rate = ['tax_class_id' => $standard['id'], 'name' => 'Sales tax', 'country' => 'pk', 'rate_bps' => 1700];
        $this->actingAs($this->owner)->postJson('/api/v1/tax/rates', [...$rate, 'rate_bps' => 10001])->assertStatus(422);
        $this->actingAs($this->owner)->postJson('/api/v1/tax/rates', [...$rate, 'country' => null, 'region' => 'Punjab'])->assertStatus(422)->assertJsonValidationErrors('region');
        $this->actingAs($this->owner)->postJson('/api/v1/tax/rates', [...$rate, 'starts_on' => '2026-12-01', 'ends_on' => '2026-11-01'])->assertStatus(422);
        $created = $this->actingAs($this->owner)->postJson('/api/v1/tax/rates', $rate)->assertCreated()->json('data');
        $this->assertSame('PK', $created['country']);

        // Another store's class is never usable here, on a rate or a product.
        [$other] = [Store::factory()->create()];
        $foreign = DB::table('tax_classes')->insertGetId(['store_id' => $other->id, 'name' => 'Theirs', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->owner)->postJson('/api/v1/tax/rates', [...$rate, 'tax_class_id' => $foreign])->assertStatus(422);
        $this->actingAs($this->owner)->putJson("/api/v1/products/{$this->product->public_id}", ['tax_class_id' => $foreign])->assertStatus(422);
        $this->actingAs($this->owner)->putJson("/api/v1/products/{$this->product->public_id}", ['tax_class_id' => $reduced['id']])->assertOk()->assertJsonPath('data.tax_class_id', $reduced['id']);

        // The generic settings API neither lists nor changes tax settings (no way round tax.manage or the rate check).
        $this->actingAs($this->owner)->putJson('/api/v1/store/settings/tax.enabled', ['value' => true])->assertForbidden()->assertJsonPath('code', 'managed_on_tax_page');
        $this->assertSame([], array_values(array_filter(array_column($this->actingAs($this->owner)->getJson('/api/v1/store/settings')->json('data'), 'key'), fn ($k) => str_starts_with($k, 'tax.'))));

        // Settings: validated, then on; the test calculation works before and after.
        $this->actingAs($this->owner)->putJson('/api/v1/tax/settings', ['rounding' => 'sometimes'])->assertStatus(422);
        $preview = $this->actingAs($this->owner)->postJson('/api/v1/tax/preview', ['amount_minor' => 10000, 'tax_class_id' => $standard['id'], 'country' => 'PK'])->assertOk()->json('data');
        $this->assertSame([1700, 11700, false], [$preview['tax_minor'], $preview['total_minor'], $preview['enabled']]);
        $this->actingAs($this->owner)->putJson('/api/v1/tax/settings', ['enabled' => true, 'label' => 'GST'])->assertOk()->assertJsonPath('data.label', 'GST');

        // A class with rates, or the default class, is not deleted.
        $this->actingAs($this->owner)->deleteJson("/api/v1/tax/classes/{$standard['id']}")->assertStatus(422);
        $this->actingAs($this->owner)->deleteJson("/api/v1/tax/classes/{$reduced['id']}")->assertStatus(422);
        $this->actingAs($this->owner)->deleteJson("/api/v1/tax/rates/{$created['id']}")->assertNoContent();
        $this->actingAs($this->owner)->deleteJson("/api/v1/tax/classes/{$standard['id']}")->assertNoContent();

        foreach (['tax.class_created', 'tax.rate_created', 'tax.settings_updated', 'tax.rate_deleted', 'tax.class_deleted'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'store_id' => $this->store->id]);
        }
        // Exemptions need tax.manage, not only customer access.
        $customer = Customer::query()->create(['name' => 'Trust', 'email' => 'trust2@example.com']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($manager)->putJson("/api/v1/customers/{$customer->public_id}/tax-exemption", ['tax_exempt' => true, 'tax_exemption_reference' => 'X'])->assertForbidden();
    }
}
