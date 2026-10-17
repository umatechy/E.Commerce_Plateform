<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * UI-17 — the header bell: what needs attention, counted only for a user
 * who may open the page it links to, and only the active store's.
 */
final class AttentionTest extends TestCase
{
    use RefreshDatabase;

    private function member(Store $store, string $role, array $permissions = []): User
    {
        $roleModel = $role === 'owner' ? $this->systemRole($store, 'owner') : Role::factory()->for($store)->create(['slug' => $role]);
        foreach ($permissions as $key) {
            $id = Permission::query()->firstOrCreate(['key' => $key], ['group' => explode('.', $key)[0], 'description' => $key])->id;
            DB::table('permission_role')->insert(['role_id' => $roleModel->id, 'permission_id' => $id]);
        }
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $roleModel->id, 'status' => 'active']);

        return $user;
    }

    private function orders(Store $store, string $status, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Order::factory()->create(['store_id' => $store->id, 'status' => $status]);
        }
    }

    public function test_the_owner_sees_orders_to_confirm_and_to_ship_and_that_the_store_is_not_open(): void
    {
        $store = Store::factory()->create(['activated_at' => null]);
        $this->orders($store, 'pending_confirmation', 2);
        $this->orders($store, 'confirmed', 3);
        $this->orders($store, 'delivered', 4);
        $other = Store::factory()->create();
        $this->orders($other, 'pending_confirmation', 5);

        $items = collect($this->actingAs($this->member($store, 'owner'))->getJson('/api/v1/attention')->assertOk()->json('data'))->keyBy('key');

        $this->assertSame(2, $items['orders_to_confirm']['count']);
        $this->assertSame('/orders?status=pending_confirmation', $items['orders_to_confirm']['href']);
        $this->assertSame(3, $items['orders_to_ship']['count']);
        $this->assertSame(1, $items['store_not_open']['count']);
        $this->assertFalse($items->has('returns_requested'), 'nothing is listed with a count of zero');
    }

    public function test_a_staff_member_sees_only_what_their_role_may_open(): void
    {
        $store = Store::factory()->create(['activated_at' => null]);
        $this->orders($store, 'pending_confirmation', 2);

        $this->actingAs($this->member($store, 'packer', ['inventory.view']))->getJson('/api/v1/attention')->assertOk()->assertExactJson(['data' => []]);
        $this->actingAs($this->member($store, 'sales', ['orders.view']))->getJson('/api/v1/attention')->assertOk()->assertJsonPath('data.0.key', 'orders_to_confirm');
    }
}
