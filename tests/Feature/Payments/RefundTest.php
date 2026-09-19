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
use App\Domain\Payments\Exceptions\RefundExceedsRefundableBalanceException;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B7 — Refunds, refundable balance validation, idempotency
 * (Module 12 §46-49).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class RefundTest extends TestCase
{
    use RefreshDatabase;

    private function paidOrder(int $amount = 10000): array
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create();
        foreach (['orders.basic', 'payment.cod'] as $feature) {
            $package->entitlements()->create(['key' => $feature, 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        }
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $order = Order::factory()->for($store)->create(['grand_total_minor' => $amount, 'currency' => 'USD']);

        app(TenantContext::class)->resolveToStore($store->id);
        $payment = app(PaymentService::class)->createForOrder($order, PaymentMethod::CashOnDelivery, 'idem-refund-'.$store->id);
        app(PaymentService::class)->recordManualConfirmation($payment, $amount, 'CASH-1', null, actorId: 1);

        $role = Role::factory()->for($store)->create(['slug' => 'owner']);
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $role->id, 'status' => 'active']);

        return [$store, $order, $payment->fresh(), $owner];
    }

    public function test_full_refund_marks_payment_refunded(): void
    {
        [$store, $order, $payment, $owner] = $this->paidOrder();

        $response = $this->actingAs($owner)->postJson("/api/v1/payments/{$payment->id}/refund", [
            'amount_minor' => 10000, 'idempotency_key' => 'refund-full',
        ]);

        $response->assertCreated();
        $this->assertSame('refunded', $payment->fresh()->status->value);
    }

    public function test_partial_refund_marks_payment_partially_refunded(): void
    {
        [$store, $order, $payment, $owner] = $this->paidOrder();

        $this->actingAs($owner)->postJson("/api/v1/payments/{$payment->id}/refund", [
            'amount_minor' => 3000, 'idempotency_key' => 'refund-partial',
        ])->assertCreated();

        $this->assertSame('partially_refunded', $payment->fresh()->status->value);
        $this->assertSame(7000, $payment->fresh()->refundableAmountMinor());
    }

    public function test_two_partial_refunds_track_remaining_refundable_balance_independently(): void
    {
        [$store, $order, $payment, $owner] = $this->paidOrder(amount: 10000);
        $service = app(PaymentService::class);

        $service->refund($payment, 2000, null, actorId: $owner->id, idempotencyKey: 'r1');
        $service->refund($payment->fresh(), 3000, null, actorId: $owner->id, idempotencyKey: 'r2');

        $this->assertSame(5000, $payment->fresh()->refundableAmountMinor());
    }

    public function test_refund_cannot_exceed_refundable_balance(): void
    {
        [$store, $order, $payment, $owner] = $this->paidOrder(amount: 5000);

        $response = $this->actingAs($owner)->postJson("/api/v1/payments/{$payment->id}/refund", [
            'amount_minor' => 6000, 'idempotency_key' => 'refund-too-much',
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'refund_exceeds_refundable_balance');
    }

    public function test_duplicate_refund_idempotency_key_does_not_double_refund(): void
    {
        [$store, $order, $payment, $owner] = $this->paidOrder(amount: 10000);
        $service = app(PaymentService::class);

        $service->refund($payment, 5000, null, actorId: $owner->id, idempotencyKey: 'same-refund-key');
        $service->refund($payment->fresh(), 5000, null, actorId: $owner->id, idempotencyKey: 'same-refund-key');

        $this->assertSame(5000, $payment->fresh()->refundableAmountMinor()); // NOT 0
    }

    public function test_refund_requires_permission(): void
    {
        [$store, $order, $payment, $owner] = $this->paidOrder();
        $role = Role::factory()->for($store)->create(['slug' => 'staff-no-refund']);
        $staff = User::factory()->create();
        $store->users()->attach($staff, ['role_id' => $role->id, 'status' => 'active']);

        $response = $this->actingAs($staff)->postJson("/api/v1/payments/{$payment->id}/refund", [
            'amount_minor' => 1000, 'idempotency_key' => 'unauthorized-refund',
        ]);

        $response->assertStatus(403);
    }
}
