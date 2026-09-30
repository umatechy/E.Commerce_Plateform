<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerAccount;

use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Payments\Models\Payment;
use App\Domain\Shipping\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase B25 — "My orders": a customer sees exactly their own orders. */
final class CustomerOrderHistoryTest extends TestCase
{
    use InteractsWithCustomerAccounts, RefreshDatabase;

    private function orderFor(Customer $customer, array $attributes = []): Order
    {
        return Order::factory()->create(['store_id' => $customer->store_id, 'customer_id' => $customer->id, ...$attributes]);
    }

    public function test_the_list_shows_only_this_customers_orders_newest_first(): void
    {
        $store = $this->openStore();
        $customer = $this->registered($store, ['email' => 'amna@example.com']);
        $old = $this->orderFor($customer, ['created_at' => now()->subDays(3)]);
        $new = $this->orderFor($customer);
        $this->orderFor($this->registered($store));
        // A guest order with the same email belongs to a different customer row.
        Order::factory()->create(['store_id' => $store->id, 'customer_id' => Customer::factory()->for($store)->create(['email' => 'amna@example.com', 'password' => null])->id]);

        $response = $this->getJson('/api/v1/customer/orders', $this->as($customer))->assertOk();

        $this->assertSame([$new->public_id, $old->public_id], array_column($response->json('data.orders'), 'id'));
        $response->assertJsonPath('data.pagination.total', 2);
        $this->assertStringNotContainsString('"customer_id"', $response->getContent());
    }

    public function test_the_detail_includes_items_payments_and_shipments(): void
    {
        $store = $this->openStore();
        $customer = $this->registered($store);
        $order = $this->orderFor($customer, ['shipping_address_snapshot' => ['name' => 'Amna', 'line1' => '12 Mall Road', 'city' => 'Lahore', 'country' => 'PK']]);
        OrderItem::factory()->create(['order_id' => $order->id, 'store_id' => $store->id, 'product_name_snapshot' => 'Field Jacket', 'quantity' => 2, 'unit_price_minor' => 500, 'line_total_minor' => 1000]);
        Payment::factory()->create(['store_id' => $store->id, 'order_id' => $order->id]);
        Shipment::factory()->create([
            'store_id' => $store->id, 'order_id' => $order->id, 'tracking_number' => 'TRK123',
            'warehouse_id' => \App\Domain\Inventory\Models\Warehouse::query()->where('is_default', true)->value('id'),
        ]);

        $this->getJson("/api/v1/customer/orders/{$order->public_id}", $this->as($customer))->assertOk()
            ->assertJsonPath('data.number', $order->order_number)
            ->assertJsonPath('data.items.0.name', 'Field Jacket')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.payments.0.method', 'cod')
            ->assertJsonPath('data.shipments.0.tracking_number', 'TRK123')
            ->assertJsonPath('data.shipping_address.city', 'Lahore');
    }

    public function test_another_customers_order_does_not_exist(): void
    {
        $store = $this->openStore();
        $order = $this->orderFor($this->registered($store));

        $this->getJson("/api/v1/customer/orders/{$order->public_id}", $this->as($this->registered($store)))
            ->assertNotFound()->assertJsonPath('code', 'order_not_found');
    }
}
