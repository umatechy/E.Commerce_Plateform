<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UI-23 — dashboard insights from the store's own figures: a best seller
 * running low, orders waiting too long; nothing invented, nothing for a
 * role without reports.
 */
final class InsightsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(Store $store): User
    {
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);

        return $user;
    }

    public function test_a_fast_seller_running_low_and_orders_waiting_too_long_are_suggested(): void
    {
        $store = Store::factory()->create();
        $warehouse = Warehouse::factory()->for($store)->create();
        $shawl = Product::factory()->for($store)->create(['name' => 'Wool Shawl', 'status' => 'active']);
        Inventory::factory()->for($store)->create(['warehouse_id' => $warehouse->id, 'product_id' => $shawl->id, 'on_hand' => 3, 'reserved' => 0]);
        $order = Order::factory()->create(['store_id' => $store->id, 'status' => 'confirmed']);
        OrderItem::factory()->create(['store_id' => $store->id, 'order_id' => $order->id, 'product_id' => $shawl->id, 'quantity' => 4]);
        $old = Order::factory()->create(['store_id' => $store->id, 'status' => 'pending_confirmation']);
        $old->forceFill(['created_at' => now()->subDays(2)])->save();

        $items = collect($this->actingAs($this->owner($store))->getJson('/api/v1/insights')->assertOk()->json('data'))->keyBy('key');

        $this->assertSame([['id' => $shawl->public_id, 'name' => 'Wool Shawl', 'sold' => 4, 'left' => 3]], $items['selling_out']['values']['products']);
        $this->assertSame(1, $items['waiting_long']['values']['count']);
        $this->assertSame('Wool Shawl', $items['top_product']['values']['name']);
    }

    public function test_a_role_without_reports_gets_no_insights(): void
    {
        $store = Store::factory()->create();
        $role = Role::factory()->for($store)->create(['slug' => 'packer']);
        $staff = User::factory()->create();
        $store->users()->attach($staff, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($staff)->getJson('/api/v1/insights')->assertForbidden();
    }
}
