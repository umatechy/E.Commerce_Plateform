<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Payments\Models\Payment;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Shipping\Models\ShippingRate;
use App\Domain\Shipping\Models\ShippingZone;
use App\Domain\Tenancy\Models\Store;
use Database\Seeders\PackageSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B31 (gap G6) — what the admin UI needs from the server:
 * the shell's shared props, the page routes and their boundaries, and
 * the small API additions (public ids in URLs, list filters, the fields
 * the pages show). Every test also holds the rule that the UI is not the
 * security boundary: the API refuses on its own.
 */
final class AdminUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // System roles get their permissions from the catalog when a store is created.
        $this->seed(PermissionSeeder::class);
    }

    private function member(Store $store, string $role): User
    {
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $this->systemRole($store, $role)->id, 'status' => 'active']);

        return $user;
    }

    /** @return array{0: Store, 1: User} */
    private function storeWithOwner(array $features = ['products.basic', 'orders.basic']): array
    {
        $store = Store::factory()->create();
        $this->entitle($store, $features);

        return [$store, $this->member($store, 'owner')];
    }

    // --- The shell's shared props ---

    public function test_the_shell_gets_the_owners_access_package_features_stores_and_currency(): void
    {
        [$store, $owner] = $this->storeWithOwner(['products.basic', 'theme.custom_css']);
        $package = Package::query()->latest('id')->firstOrFail();
        $package->entitlements()->create(['key' => 'custom_roles.enabled', 'type' => EntitlementType::Feature, 'boolean_value' => false]);
        $package->entitlements()->create(['key' => 'max_products', 'type' => EntitlementType::UsageLimit, 'limit_value' => 5]);

        $this->actingAs($owner)->withoutVite()->get('/')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('auth.is_owner', true)
            ->where('auth.activeStore.id', $store->id)
            ->where('auth.features', ['products.basic' => true, 'theme.custom_css' => true, 'custom_roles.enabled' => false]) // limits are not features
            ->where('auth.package.name', $package->name)
            ->where('auth.stores', [['id' => $store->id, 'name' => $store->name]])
            // A new store prices in PKR (owner decision 2026-10-03); the six offered currencies come along.
            ->where('auth.currency', 'PKR')
            ->where('auth.currencies', ['PKR', 'USD', 'EUR', 'GBP', 'AED', 'SAR']));
    }

    public function test_a_staff_member_gets_exactly_the_permissions_of_the_role_and_never_another_stores(): void
    {
        [$store] = $this->storeWithOwner();
        $staff = $this->member($store, 'staff');
        [$other] = $this->storeWithOwner();

        $this->actingAs($staff)->withoutVite()->get('/')->assertOk()->assertInertia(fn ($page) => $page
            ->where('auth.is_owner', false)
            ->where('auth.permissions', fn ($keys) => collect($keys)->sort()->values()->all() === ['orders.view', 'products.view', 'returns.view', 'support.reply', 'support.view'])
            ->where('auth.stores', fn ($stores) => collect($stores)->pluck('id')->all() === [$store->id] && ! collect($stores)->pluck('id')->contains($other->id)));
    }

    public function test_a_signed_out_visitor_gets_no_access_data(): void
    {
        $this->withoutVite()->get('/')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Welcome')->where('auth.user', null)->where('auth.permissions', [])->where('auth.features', [])->where('auth.stores', []));
    }

    // --- Page routes ---

    public function test_every_store_admin_page_renders_for_a_member_and_needs_a_session(): void
    {
        [, $owner] = $this->storeWithOwner();
        $id = '01JADMINPAGE00000000000001';

        $pages = [
            '/products' => 'Catalog/Products', '/products/new' => 'Catalog/ProductEdit', "/products/{$id}" => 'Catalog/ProductEdit',
            '/categories' => 'Catalog/Categories', '/brands' => 'Catalog/Brands', '/attributes' => 'Catalog/Attributes',
            '/inventory' => 'Inventory/Index', '/warehouses' => 'Inventory/Warehouses',
            '/orders' => 'Orders/Index', '/orders/new' => 'Orders/Create', "/orders/{$id}" => 'Orders/Show',
            '/payments' => 'Payments/Index', '/shipments' => 'Shipping/Shipments', '/shipping' => 'Shipping/Settings',
            '/customers' => 'Customers/Index',
            '/promotions' => 'Marketing/Promotions', '/campaigns' => 'Marketing/Campaigns', '/segments' => 'Marketing/Segments',
            '/content/pages' => 'Content/Pages', '/content/redirects' => 'Content/Redirects', '/content/seo' => 'Content/Seo',
            '/storefront/theme' => 'Storefront/Theme', '/domains' => 'Storefront/Domains',
            '/reports' => 'Analytics/Reports', '/notifications' => 'Communication/Index',
            '/team' => 'Team/Index', '/team/roles' => 'Team/Roles', '/billing' => 'Billing/Overview',
            '/backups' => 'Backups/Index', '/store-health' => 'StoreHealth/Index', '/security' => 'Security/Index',
            '/settings' => 'Settings/Index', '/settings/audit-log' => 'Settings/AuditLog', '/settings/developer' => 'Settings/Developer',
        ];

        foreach (array_keys($pages) as $path) {
            $this->get($path)->assertRedirect('/login');
        }

        $this->actingAs($owner);
        foreach ($pages as $path => $component) {
            // assertInertia also checks that the page component file exists.
            $this->withoutVite()->get($path)->assertOk()->assertInertia(fn ($page) => $page->component($component));
        }

        $this->withoutVite()->get("/products/{$id}")->assertInertia(fn ($page) => $page->where('productId', $id));
        $this->withoutVite()->get('/products/new')->assertInertia(fn ($page) => $page->where('productId', null));
        $this->withoutVite()->get("/orders/{$id}")->assertInertia(fn ($page) => $page->where('orderId', $id));
        $this->get('/products/not-an-id')->assertNotFound();
    }

    public function test_the_super_admin_pages_are_for_platform_staff_only(): void
    {
        [$store, $owner] = $this->storeWithOwner();
        $platform = User::factory()->create(['platform_role' => 'support_agent']);

        $pages = [
            '/super-admin' => 'SuperAdmin/Dashboard', '/super-admin/stores' => 'SuperAdmin/Stores', "/super-admin/stores/{$store->id}" => 'SuperAdmin/Store',
            '/super-admin/users' => 'SuperAdmin/Users', '/super-admin/packages' => 'SuperAdmin/Packages', '/super-admin/billing' => 'SuperAdmin/Billing',
            '/super-admin/settings' => 'SuperAdmin/Settings', '/super-admin/monitoring' => 'SuperAdmin/Monitoring', '/super-admin/audit-log' => 'SuperAdmin/AuditLog',
            '/super-admin/catalog' => 'SuperAdmin/Platform', '/super-admin/backups' => 'SuperAdmin/Backups', '/super-admin/support' => 'SuperAdmin/Support',
        ];

        // A Store Owner is refused the pages themselves, not only their data.
        $this->actingAs($owner);
        foreach (array_keys($pages) as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->getJson('/api/v1/super-admin/stores')->assertForbidden();

        $this->actingAs($platform);
        foreach ($pages as $path => $component) {
            $this->withoutVite()->get($path)->assertOk()->assertInertia(fn ($page) => $page->component($component)->where('auth.user.is_platform_staff', true));
        }
    }

    public function test_an_owner_without_two_step_sign_in_is_sent_to_security_from_the_new_pages_too(): void
    {
        config(['security.mfa.required_for_store_owners' => true]);
        [, $owner] = $this->storeWithOwner();

        $this->actingAs($owner);
        foreach (['/products', '/orders/new', '/settings', '/reports', '/storefront/theme'] as $path) {
            $this->get($path)->assertRedirect('/security');
        }
        $this->getJson('/api/v1/products')->assertStatus(403)->assertJsonPath('code', 'mfa_enrollment_required');
        $this->getJson('/api/v1/permissions')->assertStatus(403)->assertJsonPath('code', 'mfa_enrollment_required');
        $this->withoutVite()->get('/security')->assertOk()->assertInertia(fn ($page) => $page->where('auth.mfa_enrollment_required', true));
    }

    // --- Public ids in URLs ---

    public function test_the_id_the_api_returns_works_in_the_url_and_another_stores_never_does(): void
    {
        [$store, $owner] = $this->storeWithOwner();
        [$otherStore] = $this->storeWithOwner();
        $product = Product::factory()->for($store)->create(['name' => 'Mine']);
        $foreign = Product::factory()->for($otherStore)->create();
        $order = Order::factory()->for($store)->create(['status' => OrderStatus::Confirmed]);

        $this->actingAs($owner);
        $id = $this->getJson('/api/v1/products')->assertOk()->json('data.0.id');
        $this->assertSame($product->public_id, $id);

        $this->getJson("/api/v1/products/{$id}")->assertOk()->assertJsonPath('data.name', 'Mine');
        $this->getJson("/api/v1/products/{$product->id}")->assertOk(); // the numeric key still resolves
        $this->putJson("/api/v1/products/{$id}", ['name' => 'Renamed'])->assertOk();
        $this->assertSame('Renamed', $product->refresh()->name);

        // Tenant isolation is unchanged: another store's public id is a 404.
        $this->getJson("/api/v1/products/{$foreign->public_id}")->assertNotFound();
        $this->putJson("/api/v1/products/{$foreign->public_id}", ['name' => 'Taken'])->assertNotFound();

        $this->getJson("/api/v1/orders/{$order->public_id}")->assertOk()->assertJsonPath('data.order_number', $order->order_number);
        $this->getJson('/api/v1/orders/01J00000000000000000000000')->assertNotFound(); // a ULID nobody has
        $this->getJson("/api/v1/orders/{$order->id}abc")->assertNotFound(); // not read as the number it starts with
    }

    // --- List filters and the fields the pages show ---

    public function test_the_product_list_is_searched_and_filtered_by_the_server(): void
    {
        [$store, $owner] = $this->storeWithOwner();
        $brand = Brand::factory()->for($store)->create();
        $category = Category::factory()->for($store)->create();
        $shirt = Product::factory()->for($store)->create(['name' => 'Blue Shirt', 'sku' => 'SH-1', 'status' => 'active', 'brand_id' => $brand->id]);
        $shirt->categories()->sync([$category->id]);
        Product::factory()->for($store)->create(['name' => 'Red Mug', 'sku' => 'MG-9', 'status' => 'draft']);

        $this->actingAs($owner);
        $this->getJson('/api/v1/products?search=shirt')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Blue Shirt');
        $this->getJson('/api/v1/products?search=MG-9')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Red Mug');
        $this->getJson('/api/v1/products?status=draft')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Red Mug');
        $this->getJson('/api/v1/products?search=100%25')->assertOk()->assertJsonCount(0, 'data'); // a wildcard is text, not a pattern
        $this->getJson('/api/v1/products?status=nonsense')->assertStatus(422);

        // What the editor needs to show the product as it is.
        $this->getJson("/api/v1/products/{$shirt->public_id}")->assertOk()
            ->assertJsonPath('data.internal_id', $shirt->id)
            ->assertJsonPath('data.brand_id', $brand->id)
            ->assertJsonPath('data.category_ids', [$category->id]);
    }

    public function test_the_order_list_filters_and_an_order_names_its_customer(): void
    {
        [$store, $owner] = $this->storeWithOwner();
        Order::factory()->for($store)->create(['order_number' => 'ORD-000111', 'status' => OrderStatus::Confirmed, 'guest_name' => 'Ayesha Khan', 'guest_email' => 'ayesha@example.com']);
        Order::factory()->for($store)->create(['order_number' => 'ORD-000222', 'status' => OrderStatus::Cancelled]);

        $this->actingAs($owner);
        $this->getJson('/api/v1/orders?status=cancelled')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.order_number', 'ORD-000222');
        $this->getJson('/api/v1/orders?search=000111')->assertJsonCount(1, 'data')->assertJsonPath('data.0.guest_email', 'ayesha@example.com');
        $this->getJson('/api/v1/orders?search=ayesha')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/orders?status=shipped')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/orders?status=whatever')->assertStatus(422);
    }

    public function test_payments_name_their_order_and_can_be_listed_for_one_order(): void
    {
        [$store, $owner] = $this->storeWithOwner();
        $order = Order::factory()->for($store)->create();
        $other = Order::factory()->for($store)->create();
        $payment = Payment::factory()->for($store)->create(['order_id' => $order->id, 'amount_minor' => 4200]);
        Payment::factory()->for($store)->create(['order_id' => $other->id]);

        $this->actingAs($owner);
        $this->getJson('/api/v1/payments')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/payments?order={$order->public_id}")->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order.order_number', $order->order_number);
        $this->getJson("/api/v1/payments/{$payment->public_id}")->assertOk()
            ->assertJsonPath('data.order.id', $order->public_id)
            ->assertJsonPath('meta.refundable_amount_minor', 0); // nothing has been paid yet
    }

    public function test_stock_rows_name_their_product_and_filter_by_stock_state(): void
    {
        [$store, $owner] = $this->storeWithOwner();
        $warehouse = Warehouse::query()->withoutTenantScope()->where('store_id', $store->id)->firstOrFail();
        $low = Product::factory()->for($store)->create(['name' => 'Low Item']);
        $fine = Product::factory()->for($store)->create(['name' => 'Fine Item']);
        Inventory::factory()->for($store)->create(['warehouse_id' => $warehouse->id, 'product_id' => $low->id, 'on_hand' => 2, 'reserved' => 0, 'reorder_point' => 5]);
        Inventory::factory()->for($store)->create(['warehouse_id' => $warehouse->id, 'product_id' => $fine->id, 'on_hand' => 50, 'reserved' => 0, 'reorder_point' => 5]);

        $this->actingAs($owner);
        $this->getJson('/api/v1/inventory')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/inventory?stock=low')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product.name', 'Low Item')->assertJsonPath('data.0.product.id', $low->public_id);
        $this->getJson('/api/v1/inventory?stock=out')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/warehouses')->assertOk()->assertJsonPath('data.0.internal_id', $warehouse->id);
    }

    public function test_the_shipping_rates_of_the_store_are_listed_and_only_its_own(): void
    {
        [$store, $owner] = $this->storeWithOwner();
        [$otherStore, $otherOwner] = $this->storeWithOwner();
        $zone = ShippingZone::factory()->for($store)->create();
        $method = ShippingMethod::factory()->for($store)->create();
        ShippingRate::factory()->for($store)->for($zone, 'zone')->for($method, 'method')->create(['base_cost_minor' => 750]);

        // Each store starts with one rate (its pickup method); this store now has two.
        $own = $this->actingAs($owner)->getJson('/api/v1/shipping/rates')->assertOk()->json('data');
        $this->assertCount(2, $own);
        $this->assertContains(750, array_column($own, 'base_cost_minor'));

        $foreign = $this->actingAs($otherOwner)->getJson('/api/v1/shipping/rates')->assertOk()->json('data');
        $this->assertCount(1, $foreign);
        $this->assertNotContains(750, array_column($foreign, 'base_cost_minor'));
        $this->assertNotNull($otherStore->id);

        // The API, not the menu, keeps a role without the permission out.
        $this->actingAs($this->member($store, 'staff'))->getJson('/api/v1/shipping/rates')->assertForbidden();
    }

    public function test_the_permission_catalog_is_for_those_who_may_see_roles(): void
    {
        [$store, $owner] = $this->storeWithOwner();

        $catalog = $this->actingAs($owner)->getJson('/api/v1/permissions')->assertOk()->json('data');
        $this->assertContains('products.view', array_column($catalog, 'key'));
        $this->assertSame(['key', 'group', 'description'], array_keys($catalog[0]));

        $this->actingAs($this->member($store, 'staff'))->getJson('/api/v1/permissions')->assertForbidden();
    }

    public function test_a_package_is_addressed_by_its_code_or_its_number(): void
    {
        $platform = User::factory()->create(['platform_role' => 'support_agent']);
        $package = Package::factory()->create(['code' => 'starter-x', 'name' => 'Starter']);

        $this->actingAs($platform);
        $this->putJson('/api/v1/super-admin/packages/starter-x', ['name' => 'Starter Plus'])->assertOk()->assertJsonPath('data.name', 'Starter Plus');
        $this->putJson("/api/v1/super-admin/packages/{$package->id}", ['name' => 'Starter Max'])->assertOk()->assertJsonPath('data.name', 'Starter Max');
        $this->putJson('/api/v1/super-admin/packages/no-such-package', ['name' => 'X'])->assertNotFound();
    }
    public function test_stock_kept_per_variant_names_the_variants_product(): void
    {
        [$store, $owner] = $this->storeWithOwner();
        $warehouse = Warehouse::query()->withoutTenantScope()->where('store_id', $store->id)->firstOrFail();
        $product = Product::factory()->for($store)->create(['name' => 'Rose Attar']);
        $variant = ProductVariant::query()->withoutTenantScope()->create(['store_id' => $store->id, 'product_id' => $product->id, 'sku' => 'ATT-12', 'option_values' => ['Size' => '12ml']]);
        Inventory::factory()->for($store)->create(['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'on_hand' => 9]);

        $this->actingAs($owner)->getJson('/api/v1/inventory')->assertOk()
            ->assertJsonPath('data.0.product.name', 'Rose Attar')
            ->assertJsonPath('data.0.variant.option_values.Size', '12ml');
    }

    public function test_a_newly_registered_store_can_create_a_product_with_the_seeded_packages(): void
    {
        // The packages as they are really seeded, not as a test builds them:
        // `products.basic` was required by the API but missing from the seeder,
        // so no real store could create a product.
        $this->seed(PackageSeeder::class);
        foreach (['basic', 'business', 'premium'] as $code) {
            $this->assertTrue(
                Package::query()->where('code', $code)->firstOrFail()->entitlements()->where('key', 'products.basic')->where('boolean_value', true)->exists(),
                "The {$code} package does not include products.basic.",
            );
        }

        $this->withHeader('Referer', 'http://localhost');
        $this->postJson('/api/v1/auth/register', [
            'name' => 'New Owner', 'email' => 'new.owner@example.com', 'password' => 'A-long-Password-71!', 'password_confirmation' => 'A-long-Password-71!', 'store_name' => 'New Shop',
        ])->assertCreated();

        $this->postJson('/api/v1/products', ['type' => 'simple', 'name' => 'First product', 'price_minor' => 1500, 'currency' => 'USD'])
            ->assertCreated()->assertJsonPath('data.name', 'First product');
    }
}
