<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase B7 — Payment tenant isolation + staff/customer principal
 * boundary regression (Module 12 Step 17 items 1-5).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class PaymentTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Store $store): User
    {
        $role = Role::factory()->for($store)->create(['slug' => 'owner']);
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    private function paymentIn(Store $store): \App\Domain\Payments\Models\Payment
    {
        $package = Package::factory()->create();
        $package->entitlements()->create(['key' => 'orders.basic', 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        $package->entitlements()->create(['key' => 'payment.cod', 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $order = Order::factory()->for($store)->create();

        app(TenantContext::class)->resolveToStore($store->id);

        return app(PaymentService::class)->createForOrder($order, PaymentMethod::CashOnDelivery, 'idem-'.uniqid());
    }

    public function test_store_a_cannot_view_store_bs_payment(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $paymentB = $this->paymentIn($storeB);

        $this->actingAs($ownerA)->getJson("/api/v1/payments/{$paymentB->id}")->assertStatus(404);
    }

    public function test_store_a_cannot_refund_store_bs_payment(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $paymentB = $this->paymentIn($storeB);

        $response = $this->actingAs($ownerA)->postJson("/api/v1/payments/{$paymentB->id}/refund", [
            'amount_minor' => 100, 'idempotency_key' => 'cross-tenant-refund',
        ]);

        $response->assertStatus(404);
    }

    public function test_payment_listing_never_includes_another_stores_payments(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $paymentB = $this->paymentIn($storeB);

        $response = $this->actingAs($ownerA)->getJson('/api/v1/payments');

        $response->assertOk();
        $response->assertJsonMissing(['id' => $paymentB->public_id]);
    }

    /** Regression of Phase B6's critical customer/staff boundary — must still hold for the new Payment routes. */
    public function test_customer_token_cannot_access_staff_payment_routes(): void
    {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        $token = $customer->createToken('t')->plainTextToken;
        $payment = $this->paymentIn($store);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/payments/{$payment->id}");

        $response->assertStatus(401);
    }
}
