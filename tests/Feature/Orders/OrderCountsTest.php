<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UI-26 — the order list's status chips: how many orders are at each
 * status, for the active store only, to those who may see its orders.
 */
final class OrderCountsTest extends TestCase
{
    use RefreshDatabase;

    private function orders(Store $store, string $status, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Order::factory()->create(['store_id' => $store->id, 'status' => $status]);
        }
    }

    public function test_counts_are_per_status_and_only_of_the_active_store(): void
    {
        $store = Store::factory()->create();
        $this->orders($store, 'confirmed', 3);
        $this->orders($store, 'shipped', 2);
        $this->orders(Store::factory()->create(), 'confirmed', 7);
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);

        $this->actingAs($owner)->getJson('/api/v1/orders/counts')->assertOk()
            ->assertJsonPath('data.all', 5)
            ->assertJsonPath('data.by_status.confirmed', 3)
            ->assertJsonPath('data.by_status.shipped', 2)
            ->assertJsonMissingPath('data.by_status.delivered');
    }

    public function test_a_role_that_may_not_see_orders_gets_no_counts(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => Role::factory()->for($store)->create(['slug' => 'packer'])->id, 'status' => 'active']);

        $this->actingAs($user)->getJson('/api/v1/orders/counts')->assertForbidden();
    }
}
