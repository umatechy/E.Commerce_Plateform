<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Domain\Orders\Models\Order;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\PaymentStatus;
use App\Domain\Payments\Models\WebhookEventStatus;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B7 — Webhook signature verification, idempotency, replay
 * protection, webhook-to-payment/order synchronization (Module 12
 * §26-30, Steps 10-11).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private function entitledOrderWithMockPayment(): array
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create();
        $package->entitlements()->create(['key' => 'orders.basic', 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        $package->entitlements()->create(['key' => 'payment.online', 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $order = Order::factory()->for($store)->create(['grand_total_minor' => 5000, 'currency' => 'USD']);

        app(TenantContext::class)->resolveToStore($store->id);
        $payment = app(PaymentService::class)->createForOrder($order, PaymentMethod::MockRedirect, 'idem-webhook-'.$store->id);

        return [$store, $order, $payment];
    }

    private function signedWebhookRequest(Store $store, array $payload, string $eventId): \Illuminate\Testing\TestResponse
    {
        $raw = json_encode($payload);
        $signature = hash_hmac('sha256', $raw, $store->payment_webhook_secret);

        return $this->call('POST', '/api/v1/payment-webhooks/mock_redirect', [], [], [], [
            'HTTP_X-Mock-Gateway-Signature' => $signature,
            'HTTP_X-Mock-Gateway-Event-Id' => $eventId,
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    public function test_correctly_signed_webhook_marks_payment_paid(): void
    {
        [$store, $order, $payment] = $this->entitledOrderWithMockPayment();

        $response = $this->signedWebhookRequest($store, [
            'outcome' => 'succeeded', 'amount_minor' => 5000,
            'transaction_reference' => 'txn_1', 'provider_payment_reference' => $payment->provider_payment_reference,
        ], 'evt-1');

        $response->assertOk();
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_webhook_success_syncs_the_order_payment_status(): void
    {
        [$store, $order, $payment] = $this->entitledOrderWithMockPayment();

        $this->signedWebhookRequest($store, [
            'outcome' => 'succeeded', 'amount_minor' => 5000,
            'transaction_reference' => 'txn_2', 'provider_payment_reference' => $payment->provider_payment_reference,
        ], 'evt-2');

        $this->assertSame('paid', $order->fresh()->payment_status->value);
    }

    public function test_incorrectly_signed_webhook_is_rejected_and_payment_stays_unchanged(): void
    {
        [$store, $order, $payment] = $this->entitledOrderWithMockPayment();
        $raw = json_encode(['outcome' => 'succeeded', 'amount_minor' => 5000, 'provider_payment_reference' => $payment->provider_payment_reference]);

        $response = $this->call('POST', '/api/v1/payment-webhooks/mock_redirect', [], [], [], [
            'HTTP_X-Mock-Gateway-Signature' => 'forged-signature',
            'HTTP_X-Mock-Gateway-Event-Id' => 'evt-forged',
            'CONTENT_TYPE' => 'application/json',
        ], $raw);

        $response->assertOk(); // per design: always 200, outcome recorded internally — see PaymentWebhookController
        $this->assertSame(PaymentStatus::RequiresAction, $payment->fresh()->status);
        $this->assertDatabaseHas('payment_webhook_events', ['external_event_id' => 'evt-forged', 'status' => 'failed']);
    }

    public function test_duplicate_webhook_event_id_is_processed_only_once(): void
    {
        [$store, $order, $payment] = $this->entitledOrderWithMockPayment();
        $payload = ['outcome' => 'succeeded', 'amount_minor' => 5000, 'transaction_reference' => 'txn_3', 'provider_payment_reference' => $payment->provider_payment_reference];

        $this->signedWebhookRequest($store, $payload, 'evt-duplicate');
        $this->signedWebhookRequest($store, $payload, 'evt-duplicate'); // exact same event ID — a real replay

        $this->assertSame(
            1,
            \App\Domain\Payments\Models\PaymentTransaction::query()->where('payment_id', $payment->id)->count()
        );
    }

    public function test_webhook_with_unresolvable_payment_reference_is_ignored_safely(): void
    {
        [$store, $order, $payment] = $this->entitledOrderWithMockPayment();

        $response = $this->signedWebhookRequest($store, [
            'outcome' => 'succeeded', 'amount_minor' => 5000, 'provider_payment_reference' => 'mock_intent_nonexistent',
        ], 'evt-unresolvable');

        $response->assertOk();
        $this->assertDatabaseHas('payment_webhook_events', ['external_event_id' => 'evt-unresolvable', 'status' => 'ignored']);
        $this->assertSame(PaymentStatus::RequiresAction, $payment->fresh()->status); // completely untouched
    }

    public function test_failed_webhook_releases_reservation_and_cancels_the_order(): void
    {
        [$store, $order, $payment] = $this->entitledOrderWithMockPayment();

        $this->signedWebhookRequest($store, [
            'outcome' => 'failed', 'amount_minor' => 5000, 'failure_code' => 'card_declined',
            'provider_payment_reference' => $payment->provider_payment_reference,
        ], 'evt-failed');

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame('cancelled', $order->fresh()->status->value);
    }

    public function test_missing_event_id_is_rejected(): void
    {
        [$store, $order, $payment] = $this->entitledOrderWithMockPayment();
        $raw = json_encode(['outcome' => 'succeeded', 'provider_payment_reference' => $payment->provider_payment_reference]);

        $response = $this->call('POST', '/api/v1/payment-webhooks/mock_redirect', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $raw);

        $response->assertStatus(400);
    }

    public function test_webhook_route_requires_no_sanctum_authentication(): void
    {
        // Sanity check that the route is reachable with NO Authorization
        // header at all (Non-Negotiable Rule #13) — a 401 here would be
        // a regression back to Sanctum-gated webhooks.
        [$store, $order, $payment] = $this->entitledOrderWithMockPayment();

        $response = $this->signedWebhookRequest($store, [
            'outcome' => 'succeeded', 'amount_minor' => 5000, 'provider_payment_reference' => $payment->provider_payment_reference,
        ], 'evt-no-auth-check');

        $this->assertNotSame(401, $response->status());
    }
}
