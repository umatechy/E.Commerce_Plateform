<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Payments\Exceptions\PaymentAlreadyExistsException;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\PaymentStatus;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B7 — Payment creation, server-authoritative amount, zero-value
 * orders (Module 12 §5-14).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class PaymentCreationTest extends TestCase
{
    use RefreshDatabase;

    private function entitledOrder(int $grandTotalMinor = 5000): array
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create();
        foreach (['orders.basic', 'payment.cod', 'payment.bank_transfer', 'payment.online'] as $feature) {
            $package->entitlements()->create(['key' => $feature, 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        }
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);

        $order = Order::factory()->for($store)->create(['grand_total_minor' => $grandTotalMinor, 'currency' => 'USD']);

        app(TenantContext::class)->resolveToStore($store->id);

        return [$store, $order];
    }

    public function test_payment_amount_is_derived_from_the_order_never_a_caller_value(): void
    {
        [$store, $order] = $this->entitledOrder(grandTotalMinor: 7500);

        $payment = app(PaymentService::class)->createForOrder($order, PaymentMethod::CashOnDelivery, 'idem-1');

        $this->assertSame(7500, $payment->amount_minor);
        $this->assertSame('USD', $payment->currency);
    }

    public function test_cod_payment_starts_pending_with_no_redirect(): void
    {
        [$store, $order] = $this->entitledOrder();

        $payment = app(PaymentService::class)->createForOrder($order, PaymentMethod::CashOnDelivery, 'idem-cod');

        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertNull(app(PaymentService::class)->redirectUrlFor($payment));
    }

    public function test_mock_redirect_payment_requires_action_and_has_a_redirect_url(): void
    {
        [$store, $order] = $this->entitledOrder();

        $payment = app(PaymentService::class)->createForOrder($order, PaymentMethod::MockRedirect, 'idem-mock');

        $this->assertSame(PaymentStatus::RequiresAction, $payment->status);
        $this->assertNotNull(app(PaymentService::class)->redirectUrlFor($payment));
        $this->assertNotNull($payment->provider_payment_reference);
    }

    public function test_zero_value_order_payment_is_marked_paid_without_a_gateway_call(): void
    {
        [$store, $order] = $this->entitledOrder(grandTotalMinor: 0);

        $payment = app(PaymentService::class)->createForOrder($order, PaymentMethod::MockRedirect, 'idem-zero');

        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->completed_at);
        $this->assertNull($payment->provider_payment_reference); // gateway was never called
    }

    public function test_a_second_payment_cannot_be_created_for_the_same_order(): void
    {
        [$store, $order] = $this->entitledOrder();
        app(PaymentService::class)->createForOrder($order, PaymentMethod::CashOnDelivery, 'idem-first');

        $this->expectException(PaymentAlreadyExistsException::class);
        app(PaymentService::class)->createForOrder($order, PaymentMethod::CashOnDelivery, 'idem-second');
    }

    public function test_duplicate_idempotency_key_returns_the_same_payment(): void
    {
        [$store, $order] = $this->entitledOrder();

        $first = app(PaymentService::class)->createForOrder($order, PaymentMethod::CashOnDelivery, 'same-key');
        $second = app(PaymentService::class)->createForOrder($order, PaymentMethod::CashOnDelivery, 'same-key');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count());
    }

    public function test_payment_amount_never_uses_order_totals_from_a_different_store(): void
    {
        [$storeA, $orderA] = $this->entitledOrder(grandTotalMinor: 1000);
        [$storeB, $orderB] = $this->entitledOrder(grandTotalMinor: 999999);

        app(TenantContext::class)->resolveToStore($storeA->id);
        $paymentA = app(PaymentService::class)->createForOrder($orderA, PaymentMethod::CashOnDelivery, 'idem-a');

        $this->assertSame(1000, $paymentA->amount_minor);
        $this->assertNotSame(999999, $paymentA->amount_minor);
    }

    public function test_staff_endpoint_requires_authentication(): void
    {
        [$store, $order] = $this->entitledOrder();
        $payment = app(PaymentService::class)->createForOrder($order, PaymentMethod::CashOnDelivery, 'idem-auth');

        $this->getJson("/api/v1/payments/{$payment->id}")->assertStatus(401);
    }

    public function test_staff_with_payments_view_permission_can_view_a_payment(): void
    {
        [$store, $order] = $this->entitledOrder();
        $payment = app(PaymentService::class)->createForOrder($order, PaymentMethod::CashOnDelivery, 'idem-view');
        $role = Role::factory()->for($store)->create(['slug' => 'owner']);
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $role->id, 'status' => 'active']);

        $response = $this->actingAs($owner)->getJson("/api/v1/payments/{$payment->id}");

        $response->assertOk();
        $response->assertJsonPath('data.method', 'cod');
    }
}
