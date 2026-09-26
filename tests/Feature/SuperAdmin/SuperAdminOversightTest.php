<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Notifications\Models\RecipientType;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentTransaction;
use App\Domain\Payments\Models\TransactionStatus;
use App\Domain\Payments\Models\TransactionType;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B16 — Payment/Notification/Domain oversight: read-only,
 * cross-store, never mutates authoritative state (Module 30 §16/§19).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class SuperAdminOversightTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_failures_are_visible_across_every_store(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $order = Order::factory()->for($store)->create();
        $payment = Payment::factory()->for($store)->create(['order_id' => $order->id]);
        PaymentTransaction::query()->create([
            'store_id' => $store->id, 'payment_id' => $payment->id, 'type' => TransactionType::Sale,
            'status' => TransactionStatus::Failed, 'amount_minor' => 1000, 'currency' => 'USD', 'idempotency_key' => uniqid(),
        ]);
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $response = $this->actingAs($superAdmin)->getJson('/api/v1/super-admin/payments/failures');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, $response->json('data.total'));
    }

    public function test_notification_failures_are_visible_across_every_store(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        NotificationMessage::query()->create([
            'store_id' => $store->id, 'message_type' => 'transactional', 'channel' => 'email',
            'recipient_type' => RecipientType::Customer, 'recipient_id' => 1, 'destination' => 'a@example.com',
            'body' => 'x', 'status' => 'failed', 'idempotency_key' => uniqid(),
        ]);
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $response = $this->actingAs($superAdmin)->getJson('/api/v1/super-admin/notifications/failures');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, $response->json('data.total'));
    }

    public function test_platform_wide_domain_list_spans_every_store(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $response = $this->actingAs($superAdmin)->getJson('/api/v1/super-admin/domains');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(2, $response->json('data.total')); // each store's own auto-created platform subdomain (Phase B14)
    }

    public function test_non_platform_staff_cannot_view_payment_failures(): void
    {
        $ordinaryUser = User::factory()->create(['platform_role' => null]);

        $response = $this->actingAs($ordinaryUser)->getJson('/api/v1/super-admin/payments/failures');

        $response->assertStatus(403);
    }
}
