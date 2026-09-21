<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Services\DashboardService;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentTransaction;
use App\Domain\Payments\Models\TransactionStatus;
use App\Domain\Payments\Models\TransactionType;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase B12 — Dashboard KPI calculation against the documented Metric
 * Dictionary (Module 22 §7/§15, Non-Negotiable).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_revenue_uses_grand_total_and_excludes_cancelled_orders(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        Order::factory()->for($store)->create(['grand_total_minor' => 10000, 'subtotal_minor' => 10000, 'status' => OrderStatus::Confirmed]);
        Order::factory()->for($store)->create(['grand_total_minor' => 99999, 'subtotal_minor' => 99999, 'status' => OrderStatus::Cancelled]);

        $summary = app(DashboardService::class)->summary(now()->startOfDay(), now()->endOfDay());

        $this->assertSame(10000, $summary['revenue_minor']);
        $this->assertSame(1, $summary['order_count']);
    }

    public function test_net_sales_equals_gross_sales_minus_discounts(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        Order::factory()->for($store)->create(['subtotal_minor' => 10000, 'discount_total_minor' => 1500, 'grand_total_minor' => 8500, 'status' => OrderStatus::Confirmed]);

        $summary = app(DashboardService::class)->summary(now()->startOfDay(), now()->endOfDay());

        $this->assertSame(10000, $summary['gross_sales_minor']);
        $this->assertSame(1500, $summary['discounts_minor']);
        $this->assertSame(8500, $summary['net_sales_minor']);
    }

    public function test_average_order_value_is_null_when_there_are_no_orders(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        $summary = app(DashboardService::class)->summary(now()->startOfDay(), now()->endOfDay());

        $this->assertNull($summary['average_order_value_minor']);
    }

    public function test_collected_amount_is_distinct_from_revenue(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $order = Order::factory()->for($store)->create(['grand_total_minor' => 10000, 'status' => OrderStatus::Confirmed]);
        $payment = Payment::factory()->for($store)->create(['order_id' => $order->id, 'amount_minor' => 10000]);
        PaymentTransaction::query()->create([
            'store_id' => $store->id, 'payment_id' => $payment->id, 'type' => TransactionType::Sale, 'status' => TransactionStatus::Succeeded,
            'amount_minor' => 4000, 'currency' => 'USD', 'idempotency_key' => uniqid(),
        ]);

        $summary = app(DashboardService::class)->summary(now()->startOfDay(), now()->endOfDay());

        $this->assertSame(10000, $summary['revenue_minor']); // full order value
        $this->assertSame(4000, $summary['collected_amount_minor']); // only what was actually collected (e.g. a partial COD scenario)
    }

    public function test_low_stock_count_uses_available_not_raw_on_hand(): void
    {
        // Regression test for the exact bug found and fixed in B12
        // (see docs/development/b12-inspection-findings.md "Bug Found
        // and Fixed") — must use (on_hand - reserved) <= reorder_point,
        // matching Inventory::isLowStock() exactly, not raw on_hand.
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $product = \App\Domain\Catalog\Models\Product::factory()->for($store)->create();
        $warehouse = Warehouse::query()->where('store_id', $store->id)->value('id');
        $inventory = Inventory::factory()->for($store)->create(['warehouse_id' => $warehouse, 'product_id' => $product->id, 'on_hand' => 10, 'reserved' => 8, 'reorder_point' => 5]);

        $this->assertTrue($inventory->isLowStock()); // available = 2, <= reorder_point 5

        $summary = app(DashboardService::class)->summary(now()->startOfDay(), now()->endOfDay());

        $this->assertSame(1, $summary['low_stock_products']);
    }

    public function test_comparison_returns_null_percent_change_when_previous_period_had_no_orders(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        Order::factory()->for($store)->create(['grand_total_minor' => 5000, 'status' => OrderStatus::Confirmed]);

        $result = app(DashboardService::class)->summaryWithComparison(now()->startOfDay(), now()->endOfDay());

        $this->assertNull($result['revenue_change_percent']);
    }
}
