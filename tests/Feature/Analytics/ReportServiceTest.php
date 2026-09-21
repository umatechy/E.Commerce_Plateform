<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Services\ReportService;
use App\Domain\Catalog\Models\Product;
use App\Domain\Marketing\Models\Campaign;
use App\Domain\Marketing\Models\CampaignRecipient;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Notifications\Models\RecipientType;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Promotions\Models\Coupon;
use App\Domain\Promotions\Models\Promotion;
use App\Domain\Promotions\Models\PromotionUsage;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B12 — Report types read authoritative source tables, never
 * duplicate business logic (Module 22 Core Principle, §16-28).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class ReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_report_groups_by_day(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        Order::factory()->for($store)->create(['grand_total_minor' => 1000, 'status' => OrderStatus::Confirmed]);
        Order::factory()->for($store)->create(['grand_total_minor' => 2000, 'status' => OrderStatus::Confirmed]);

        $rows = app(ReportService::class)->salesReport(now()->startOfDay(), now()->endOfDay());

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['order_count']);
        $this->assertSame(3000, $rows[0]['revenue_minor']);
    }

    public function test_products_report_sums_quantity_and_revenue_per_product(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $product = Product::factory()->for($store)->create();
        $order = Order::factory()->for($store)->create(['status' => OrderStatus::Confirmed]);
        OrderItem::factory()->for($order)->create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 3, 'line_total_minor' => 3000]);

        $result = app(ReportService::class)->productsReport(now()->startOfDay(), now()->endOfDay());

        $this->assertSame(3, (int) $result->items()[0]->quantity_sold);
        $this->assertSame(3000, (int) $result->items()[0]->revenue_minor);
    }

    public function test_customers_report_computes_repeat_rate(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $repeatCustomer = Customer::factory()->for($store)->create();
        Order::factory()->for($store)->create(['customer_id' => $repeatCustomer->id, 'status' => OrderStatus::Confirmed]);
        Order::factory()->for($store)->create(['customer_id' => $repeatCustomer->id, 'status' => OrderStatus::Confirmed]);
        $oneTimeCustomer = Customer::factory()->for($store)->create();
        Order::factory()->for($store)->create(['customer_id' => $oneTimeCustomer->id, 'status' => OrderStatus::Confirmed]);

        $result = app(ReportService::class)->customersReport(now()->startOfDay(), now()->endOfDay());

        $this->assertSame(2, $result['customers_with_orders']);
        $this->assertSame(1, $result['repeat_customers']);
        $this->assertSame(50.0, $result['repeat_customer_rate_percent']);
    }

    public function test_payments_report_returns_status_as_a_string_value_not_an_enum_object(): void
    {
        // Regression test for the exact bug found and fixed in B12
        // (see b12-inspection-findings.md "Second Bug Found").
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $order = Order::factory()->for($store)->create();
        Payment::factory()->for($store)->create(['order_id' => $order->id]);

        $rows = app(ReportService::class)->paymentsReport(now()->startOfDay(), now()->endOfDay());

        $this->assertIsString($rows[0]['status']);
        $this->assertIsString($rows[0]['method']);
    }

    public function test_shipping_report_groups_by_status_and_carrier(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $order = Order::factory()->for($store)->create();
        $warehouse = \App\Domain\Inventory\Models\Warehouse::query()->where('store_id', $store->id)->value('id');
        Shipment::factory()->for($store)->create(['order_id' => $order->id, 'warehouse_id' => $warehouse, 'carrier' => 'store_pickup']);

        $rows = app(ReportService::class)->shippingReport(now()->startOfDay(), now()->endOfDay());

        $this->assertSame('store_pickup', $rows[0]['carrier']);
        $this->assertIsString($rows[0]['status']);
    }

    public function test_promotions_report_reuses_the_actual_usage_ledger(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $promotion = Promotion::factory()->for($store)->create(['name' => 'Summer Sale']);
        $order = Order::factory()->for($store)->create();
        PromotionUsage::query()->create([
            'store_id' => $store->id, 'promotion_id' => $promotion->id, 'order_id' => $order->id,
            'discount_amount_minor' => 500, 'currency' => 'USD',
        ]);

        $rows = app(ReportService::class)->promotionsReport(now()->startOfDay(), now()->endOfDay());

        $this->assertSame('Summer Sale', $rows[0]['name']);
        $this->assertSame(500, $rows[0]['total_discount_minor']);
    }

    public function test_marketing_report_reuses_actual_campaign_recipient_ledger(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $campaign = Campaign::factory()->for($store)->create(['name' => 'Fall Promo']);
        $customer = Customer::factory()->for($store)->create();
        CampaignRecipient::query()->create(['store_id' => $store->id, 'campaign_id' => $campaign->id, 'customer_id' => $customer->id, 'status' => 'queued', 'queued_at' => now()]);

        $rows = app(ReportService::class)->marketingReport(now()->startOfDay(), now()->endOfDay());

        $this->assertSame('Fall Promo', $rows[0]['name']);
        $this->assertSame('queued', $rows[0]['status']);
    }

    public function test_notifications_report_never_conflates_sent_and_delivered(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        NotificationMessage::query()->create([
            'store_id' => $store->id, 'message_type' => 'transactional', 'channel' => 'email',
            'recipient_type' => RecipientType::Customer, 'recipient_id' => 1, 'destination' => 'a@example.com',
            'body' => 'x', 'status' => 'sent', 'idempotency_key' => uniqid(),
        ]);
        NotificationMessage::query()->create([
            'store_id' => $store->id, 'message_type' => 'transactional', 'channel' => 'email',
            'recipient_type' => RecipientType::Customer, 'recipient_id' => 1, 'destination' => 'a@example.com',
            'body' => 'x', 'status' => 'delivered', 'idempotency_key' => uniqid(),
        ]);

        $rows = app(ReportService::class)->notificationsReport(now()->startOfDay(), now()->endOfDay());
        $statuses = array_column($rows, 'status');

        $this->assertContains('sent', $statuses);
        $this->assertContains('delivered', $statuses);
        $this->assertNotSame(count(array_unique($statuses)), 1); // they must remain two distinct buckets
    }

    public function test_inventory_report_uses_the_authoritative_available_formula(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $product = Product::factory()->for($store)->create();
        $warehouse = \App\Domain\Inventory\Models\Warehouse::query()->where('store_id', $store->id)->value('id');
        \App\Domain\Inventory\Models\Inventory::factory()->for($store)->create(['warehouse_id' => $warehouse, 'product_id' => $product->id, 'on_hand' => 0, 'reserved' => 0]);

        $result = app(ReportService::class)->inventoryReport();

        $this->assertSame(1, $result['out_of_stock_count']);
    }
}
