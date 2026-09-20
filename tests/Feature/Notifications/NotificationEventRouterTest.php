<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Notifications\Services\NotificationEventRouter;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Phase B11 — the first real consumer of B5/B7/B8/B10's outbox events
 * (Module 21's own "wire existing events" requirement). Uses the exact
 * event names/payloads those phases already emit — none invented.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class NotificationEventRouterTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_created_event_produces_a_transactional_message(): void
    {
        Bus::fake();
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $customer = Customer::factory()->for($store)->create();
        $order = Order::factory()->for($store)->create(['customer_id' => $customer->id, 'order_number' => 'ORD-000001']);

        app(NotificationEventRouter::class)->route('order.created', ['order_id' => $order->id]);

        $message = NotificationMessage::query()->where('source_event_type', 'order.created')->first();
        $this->assertNotNull($message);
        $this->assertSame('transactional', $message->message_type->value);
        $this->assertStringContainsString('ORD-000001', $message->subject);
    }

    public function test_guest_order_created_uses_the_guest_email_as_destination(): void
    {
        Bus::fake();
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $order = Order::factory()->for($store)->create(['customer_id' => null, 'guest_email' => 'guest@example.com', 'guest_name' => 'Guest']);

        app(NotificationEventRouter::class)->route('order.created', ['order_id' => $order->id]);

        $this->assertDatabaseHas('notification_messages', ['destination' => 'guest@example.com']);
    }

    public function test_shipment_created_event_includes_the_tracking_number_when_present(): void
    {
        Bus::fake();
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $customer = Customer::factory()->for($store)->create();
        $order = Order::factory()->for($store)->create(['customer_id' => $customer->id]);
        $warehouse = \App\Domain\Inventory\Models\Warehouse::query()->where('store_id', $store->id)->value('id');
        $shipment = Shipment::factory()->for($store)->create(['order_id' => $order->id, 'warehouse_id' => $warehouse, 'tracking_number' => 'TRACK-123']);

        app(NotificationEventRouter::class)->route('shipment.created', ['shipment_id' => $shipment->id, 'order_id' => $order->id, 'carrier' => 'mock_courier']);

        $message = NotificationMessage::query()->where('source_event_type', 'shipment.created')->first();
        $this->assertStringContainsString('TRACK-123', $message->body);
        $this->assertStringNotContainsString('{{', $message->body); // regression: no unexpanded template token leaks into the final message
    }

    public function test_unrecognized_event_type_is_silently_ignored(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        app(NotificationEventRouter::class)->route('some.unrelated.event', ['foo' => 'bar']);

        $this->assertSame(0, NotificationMessage::query()->count());
    }

    public function test_marketing_recipient_queued_event_appends_a_working_unsubscribe_link(): void
    {
        Bus::fake();
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $customer = Customer::factory()->for($store)->create(['marketing_email_opt_in' => true]);
        $campaign = \App\Domain\Marketing\Models\Campaign::factory()->for($store)->create();

        app(NotificationEventRouter::class)->route('marketing.recipient_queued', ['campaign_id' => $campaign->id, 'customer_id' => $customer->id]);

        $message = NotificationMessage::query()->where('source_event_type', 'marketing.recipient_queued')->first();
        $this->assertStringContainsString('/api/v1/public/notifications/unsubscribe', $message->body);
        $this->assertStringNotContainsString('{{', $message->body);
    }

    public function test_missing_referenced_order_does_not_throw(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        app(NotificationEventRouter::class)->route('order.created', ['order_id' => 999999]);

        $this->assertSame(0, NotificationMessage::query()->count());
    }
}
