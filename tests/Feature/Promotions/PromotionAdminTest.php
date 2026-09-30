<?php

declare(strict_types=1);

namespace Tests\Feature\Promotions;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Promotions\Models\Promotion;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B9 — Staff promotion/coupon administration, promotion
 * snapshot immutability (Module 14 §58-59/§69).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class PromotionAdminTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Store $store): User
    {
        $role = $this->systemRole($store, 'owner');
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_owner_can_create_an_order_level_promotion(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->postJson('/api/v1/promotions', [
            'name' => 'Autumn Sale', 'type' => 'percentage', 'target_scope' => 'order',
            'percentage_value' => 15, 'status' => 'active',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Autumn Sale');
    }

    public function test_creating_a_product_scoped_promotion_requires_target_ids(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->postJson('/api/v1/promotions', [
            'name' => 'Product Sale', 'type' => 'percentage', 'target_scope' => 'product', 'percentage_value' => 10,
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'targets_required');
    }

    public function test_owner_can_create_a_coupon_for_a_promotion(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $promotion = Promotion::factory()->for($store)->create(['requires_coupon' => true]);

        $response = $this->actingAs($owner)->postJson('/api/v1/coupons', [
            'promotion_id' => $promotion->id, 'code' => 'NEWCODE',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('coupons', ['code_normalized' => 'NEWCODE']);
    }

    public function test_deactivating_a_coupon_soft_disables_rather_than_deletes(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $promotion = Promotion::factory()->for($store)->create(['requires_coupon' => true]);
        $coupon = \App\Domain\Promotions\Models\Coupon::factory()->for($store)->for($promotion)->create();

        $response = $this->actingAs($owner)->deleteJson("/api/v1/coupons/{$coupon->id}");

        $response->assertNoContent();
        $this->assertDatabaseHas('coupons', ['id' => $coupon->id, 'is_active' => false]);
    }

    public function test_editing_a_promotion_after_an_order_used_it_does_not_change_the_orders_historical_snapshot(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $promotion = Promotion::factory()->for($store)->create(['name' => 'Original Name', 'percentage_value' => 10]);
        $order = \App\Domain\Orders\Models\Order::factory()->for($store)->create();
        \App\Domain\Promotions\Models\OrderPromotion::query()->create([
            'store_id' => $store->id, 'order_id' => $order->id, 'promotion_id' => $promotion->id,
            'promotion_name_snapshot' => $promotion->name, 'promotion_type_snapshot' => 'percentage',
            'discount_amount_minor' => 500, 'currency' => 'USD',
        ]);

        $this->actingAs($owner)->putJson("/api/v1/promotions/{$promotion->id}", [
            'name' => 'Renamed Promotion', 'type' => 'percentage', 'target_scope' => 'order', 'percentage_value' => 50,
        ])->assertOk();

        $snapshot = \App\Domain\Promotions\Models\OrderPromotion::query()->withoutTenantScope()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('Original Name', $snapshot->promotion_name_snapshot); // untouched by the later edit
    }
}
