<?php

declare(strict_types=1);

namespace Tests\Feature\Promotions;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Promotions\Models\Promotion;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase B9 — Promotion/coupon tenant isolation + staff/customer
 * principal boundary regression (Module 14 Step 24 items 1-6).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class PromotionTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Store $store): User
    {
        $role = $this->systemRole($store, 'owner');
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_store_a_cannot_view_store_bs_promotion(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        app(TenantContext::class)->resolveToStore($storeB->id);
        $promotionB = Promotion::factory()->for($storeB)->create();

        $this->actingAs($ownerA)->getJson("/api/v1/promotions/{$promotionB->id}")->assertStatus(404);
    }

    public function test_a_stores_coupon_cannot_be_used_by_another_stores_cart(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($storeB->id);
        $promotionB = Promotion::factory()->for($storeB)->create(['requires_coupon' => true]);
        \App\Domain\Promotions\Models\Coupon::factory()->for($storeB)->for($promotionB)->create(['code' => 'XSTORE', 'code_normalized' => 'XSTORE']);

        // A guest shopping at Store A tries the coupon that only exists for Store B.
        $response = $this->postJson('/api/v1/cart/coupon', ['code' => 'XSTORE'], ['X-Store-Slug' => $storeA->slug]);

        $response->assertStatus(422)->assertJsonPath('code', 'coupon_not_eligible');
    }

    public function test_customer_token_cannot_access_staff_promotion_routes(): void
    {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/promotions');

        $response->assertStatus(401);
    }

    public function test_promotion_management_requires_permission(): void
    {
        $store = Store::factory()->create();
        $role = Role::factory()->for($store)->create(['slug' => 'no-promo-access']);
        $staff = User::factory()->create();
        $store->users()->attach($staff, ['role_id' => $role->id, 'status' => 'active']);

        $response = $this->actingAs($staff)->postJson('/api/v1/promotions', [
            'name' => 'Test', 'type' => 'percentage', 'target_scope' => 'order', 'percentage_value' => 10,
        ]);

        $response->assertStatus(403);
    }
}
