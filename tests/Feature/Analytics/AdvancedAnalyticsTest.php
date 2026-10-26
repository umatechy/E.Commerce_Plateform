<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Support\FeatureAvailability;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Returns\BuildsReturns;
use Tests\TestCase;

/**
 * Phase P3 — advanced reports (Business, `reports.advanced`) and advanced
 * analytics (Premium, `advanced_analytics.enabled`): the package decides,
 * the permission decides the money figures (also in exports), every figure
 * comes from real orders, carts, refunds and returns, and only from the
 * active store.
 */
final class AdvancedAnalyticsTest extends TestCase
{
    use BuildsReturns, RefreshDatabase;

    private function include(string ...$features): void
    {
        $package = Subscription::query()->withoutGlobalScopes()->where('store_id', $this->store->id)->sole()->package;
        foreach ($features as $key) {
            $package->entitlements()->create(['key' => $key, 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        }
        \Illuminate\Support\Facades\Cache::flush();
    }

    private function member(string $email): Customer
    {
        return Customer::factory()->for($this->store)->create(['email' => $email, 'password' => Hash::make('correct-horse-99')]);
    }

    public function test_the_advanced_reports_need_the_business_package_and_money_reports_the_financial_permission_also_for_exports(): void
    {
        $this->actingAs($this->owner)->getJson('/api/v1/reports/sales?date_filter=last_30_days')->assertOk();
        $this->actingAs($this->owner)->getJson('/api/v1/reports/inventory')->assertOk();
        foreach (['payments', 'shipping', 'promotions', 'marketing', 'notifications', 'categories'] as $report) {
            $this->actingAs($this->owner)->getJson("/api/v1/reports/{$report}?date_filter=last_30_days")->assertForbidden()->assertJsonPath('code', 'feature_not_entitled');
        }
        $this->actingAs($this->owner)->postJson('/api/v1/exports', ['report_type' => 'payments', 'date_filter' => 'last_30_days', 'idempotency_key' => 'exp-00001'])
            ->assertForbidden()->assertJsonPath('code', 'feature_not_entitled');

        // Viewing and exporting without the financial permission: the money reports are refused, also as a file.
        $staff = $this->staffWith(['analytics.view', 'analytics.export']);
        $this->asStaff();
        $this->actingAs($staff)->getJson('/api/v1/reports/sales?date_filter=last_30_days')->assertForbidden();
        $this->actingAs($staff)->postJson('/api/v1/exports', ['report_type' => 'sales', 'date_filter' => 'last_30_days', 'idempotency_key' => 'exp-00002'])->assertForbidden();
        $this->actingAs($staff)->postJson('/api/v1/exports', ['report_type' => 'products', 'date_filter' => 'last_30_days', 'idempotency_key' => 'exp-00003'])->assertSuccessful();

        $this->include('reports.advanced');
        $this->asStaff();
        $this->actingAs($this->owner)->getJson('/api/v1/reports/payments?date_filter=last_30_days')->assertOk();
        $this->actingAs($staff)->getJson('/api/v1/reports/shipping?date_filter=last_30_days')->assertOk();
        $this->actingAs($staff)->getJson('/api/v1/reports/categories?date_filter=last_30_days')->assertForbidden(); // money by category
    }

    public function test_categories_and_brands_add_up_the_products_sold(): void
    {
        $this->include('reports.advanced');
        $category = Category::factory()->for($this->store)->create(['name' => 'Attars']);
        $brand = Brand::factory()->for($this->store)->create(['name' => 'Rose House']);
        $this->product->forceFill(['primary_category_id' => $category->id, 'brand_id' => $brand->id])->save();
        $this->deliveredOrder(2);
        $this->deliveredOrder(1);

        $data = $this->actingAs($this->owner)->getJson('/api/v1/reports/categories?date_filter=last_30_days')->assertOk()->json('data');
        $this->assertSame([['id' => $category->id, 'name' => 'Attars', 'revenue_minor' => 300000, 'units' => 3, 'orders' => 2]], $data['categories']);
        $this->assertSame('Rose House', $data['brands'][0]['name']);
        $this->assertSame(3, $data['brands'][0]['units']);
    }

    public function test_analytics_are_premium_and_count_customers_sales_refunds_carts_and_products_from_real_records(): void
    {
        $this->assertFalse(FeatureAvailability::isComingSoon('advanced_analytics.enabled'), 'P3 built it');
        $this->actingAs($this->owner)->getJson('/api/v1/analytics/overview')->assertForbidden()->assertJsonPath('code', 'feature_not_entitled');
        $this->include('advanced_analytics.enabled');

        // A product with stock that nobody buys.
        $idle = Product::factory()->for($this->store)->create(['name' => 'Oud Box', 'price_minor' => 500000, 'currency' => 'PKR', 'status' => 'active', 'visibility' => 'public']);
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $inventory = Inventory::factory()->for($this->store)->for($warehouse)->create(['product_id' => $idle->id]);
        app(InventoryService::class)->setOpeningStock($inventory, 7, 'Init', actorId: $this->owner->id, idempotencyKey: 'open-idle');

        // Last year: Ayesha's first order. This period: Ayesha again (returning), Bilal (new), and a guest.
        $ayesha = $this->member('ayesha@example.com');
        $bilal = $this->member('bilal@example.com');
        $old = $this->deliveredOrder(1, $ayesha);
        DB::table('orders')->where('id', $old->id)->update(['created_at' => now()->subYear()->subDays(3)]);
        $this->deliveredOrder(2, $ayesha);                 // Rs. 2,000
        $bilalsOrder = $this->deliveredOrder(3, $bilal);   // Rs. 3,000
        $this->deliveredOrder(1);                          // guest, Rs. 1,000
        // One of Bilal's three units comes back and is refunded (Rs. 1,000).
        $id = $this->inspected($bilalsOrder, 1, 1, 0, 0);
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/approve-refund")->assertOk();
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/refund")->assertOk();

        // Carts: one became an order, one was left a day ago.
        foreach ([['converted', now()], ['active', now()->subHours(30)]] as $i => [$status, $at]) {
            $cartId = DB::table('carts')->insertGetId(['public_id' => (string) \Illuminate\Support\Str::ulid(), 'store_id' => $this->store->id, 'status' => $status, 'currency' => 'PKR', 'guest_token' => "t{$i}", 'created_at' => now(), 'updated_at' => $at,
                'converted_order_id' => $status === 'converted' ? $bilalsOrder->id : null]);
            DB::table('cart_items')->insert(['cart_id' => $cartId, 'store_id' => $this->store->id, 'product_id' => $this->product->id, 'quantity' => 1, 'price_at_add_minor' => 100000, 'created_at' => now(), 'updated_at' => now()]);
        }

        // Another store's orders never count.
        $other = Store::factory()->create(['status' => 'active']);
        DB::table('orders')->insert(['public_id' => (string) \Illuminate\Support\Str::ulid(), 'store_id' => $other->id, 'order_number' => 'X-1', 'status' => 'confirmed', 'payment_status' => 'paid', 'fulfillment_status' => 'unfulfilled', 'source' => 'storefront',
            'currency' => 'PKR', 'subtotal_minor' => 9900000, 'discount_total_minor' => 0, 'tax_total_minor' => 0, 'shipping_total_minor' => 0, 'grand_total_minor' => 9900000, 'idempotency_key' => 'x', 'created_at' => now(), 'updated_at' => now()]);

        $data = $this->actingAs($this->owner)->getJson('/api/v1/analytics/overview?date_filter=last_30_days&compare_with=previous_year')->assertOk()->json('data');

        $this->assertSame('previous_year', $data['comparison']['kind']);
        $this->assertSame(['current' => 3, 'previous' => 1, 'change_percent' => 200], $data['kpis']['orders']);
        $this->assertSame(3, $data['kpis']['ordering_customers']['current']); // Ayesha, Bilal, the guest
        $this->assertSame(1, $data['kpis']['new_customers']['current']);
        $this->assertSame(1, $data['kpis']['returning_customers']['current']);
        $this->assertSame(['gross_minor' => 600000, 'discounts_minor' => 0, 'refunds_minor' => 100000, 'net_minor' => 500000, 'shipping_minor' => 0, 'tax_minor' => 0, 'total_minor' => 600000], $data['sales']);
        $this->assertSame(500000, $data['kpis']['net_sales_minor']['current']);
        $this->assertSame(200000, $data['kpis']['average_order_value_minor']['current']);
        $this->assertSame(['guest_orders' => 1, 'registered_orders' => 2, 'repeat_rate_percent' => 50, 'orders_per_customer' => 1, 'average_lifetime_value_minor' => 300000], $data['customers']);
        $this->assertSame(['created' => 2, 'converted' => 1, 'abandoned' => 1, 'conversion_percent' => 50, 'average_order_value_minor' => 300000, 'online_payments' => 0, 'online_payment_failure_percent' => null], $data['carts']);
        $this->assertSame(['Oud Box'], array_column($data['products']['slow_movers'], 'name'));
        $this->assertSame(7, $data['products']['slow_movers'][0]['available']);
        $this->assertSame([['name' => 'Rose Attar', 'sold' => 6, 'returned' => 1, 'return_percent' => 16.7]], $data['products']['returns']);
        $this->assertCount(6, $data['cohorts']);
        $this->assertSame(1, end($data['cohorts'])['customers'], 'Bilal first ordered this month');

        // Without the financial permission: the same page, no money.
        $viewer = $this->staffWith(['analytics.view']);
        $this->asStaff();
        $limited = $this->actingAs($viewer)->getJson('/api/v1/analytics/overview?date_filter=last_30_days')->assertOk()->json('data');
        $this->assertFalse($limited['financial']);
        $this->assertNull($limited['sales']);
        $this->assertArrayNotHasKey('net_sales_minor', $limited['kpis']);
        $this->assertNull($limited['customers']['average_lifetime_value_minor']);
        $this->assertNull($limited['carts']['average_order_value_minor']);

        $this->actingAs($this->staffWith(['orders.view']))->getJson('/api/v1/analytics/overview')->assertForbidden();
        $this->actingAs($this->owner)->getJson('/api/v1/analytics/overview?compare_with=forever')->assertUnprocessable()->assertJsonPath('code', 'invalid_comparison');
    }
}
