<?php

declare(strict_types=1);

namespace Tests\Feature\DeveloperPlatform;

use App\Domain\DeveloperPlatform\Jobs\DispatchWebhookJob;
use App\Domain\DeveloperPlatform\Models\WebhookDeliveryAttempt;
use App\Domain\DeveloperPlatform\Models\WebhookSubscription;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase B18 — Webhook delivery: HMAC signing, replay protection via
 * the delivery-attempt ledger (Module 31 §37-38, Non-Negotiable).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class DispatchWebhookJobTest extends TestCase
{
    use RefreshDatabase;

    private function applicationFor(Store $store): \App\Domain\DeveloperPlatform\Models\DeveloperApplication
    {
        app(TenantContext::class)->resolveToStore($store->id);

        return \App\Domain\DeveloperPlatform\Models\DeveloperApplication::factory()->for($store)->create();
    }

    public function test_a_successful_delivery_is_signed_and_recorded(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $store = Store::factory()->create();
        $application = $this->applicationFor($store);
        $subscription = WebhookSubscription::factory()->for($store)->create(['developer_application_id' => $application->id, 'url' => 'https://93.184.216.34/hook']);

        (new DispatchWebhookJob($subscription->id, 'order.created', ['order_id' => 1], 'idem-key-1'))->handle(app(TenantContext::class));

        Http::assertSent(fn ($request) => $request->hasHeader('X-Umartechy-Signature'));
        $this->assertDatabaseHas('webhook_delivery_attempts', ['webhook_subscription_id' => $subscription->id, 'result' => 'succeeded']);
    }

    public function test_a_previously_succeeded_delivery_is_never_resent(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $store = Store::factory()->create();
        $application = $this->applicationFor($store);
        $subscription = WebhookSubscription::factory()->for($store)->create(['developer_application_id' => $application->id]);
        WebhookDeliveryAttempt::query()->create([
            'store_id' => $store->id, 'webhook_subscription_id' => $subscription->id, 'event_type' => 'order.created',
            'idempotency_key' => 'idem-key-1', 'attempt_number' => 1, 'result' => 'succeeded', 'response_status' => 200,
        ]);

        (new DispatchWebhookJob($subscription->id, 'order.created', ['order_id' => 1], 'idem-key-1'))->handle(app(TenantContext::class));

        Http::assertNothingSent();
    }

    public function test_a_disabled_subscription_never_receives_a_delivery(): void
    {
        Http::fake();
        $store = Store::factory()->create();
        $application = $this->applicationFor($store);
        $subscription = WebhookSubscription::factory()->for($store)->create(['developer_application_id' => $application->id, 'status' => 'disabled']);

        (new DispatchWebhookJob($subscription->id, 'order.created', ['order_id' => 1], 'idem-key-1'))->handle(app(TenantContext::class));

        Http::assertNothingSent();
    }
}
