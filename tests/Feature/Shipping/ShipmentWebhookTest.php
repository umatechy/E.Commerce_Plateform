<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\PaymentStatus as OrderPaymentStatus;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase B8 — Carrier webhook signature verification, idempotency,
 * replay, webhook-to-shipment synchronization (Module 13 §53, Steps
 * 13/20).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class ShipmentWebhookTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Store, 1: Shipment} */
    private function labelCreatedShipment(): array
    {
        $store = Store::factory()->create();
        $package = Package::factory()->create();
        $package->entitlements()->create(['key' => 'orders.basic', 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        Subscription::factory()->for($store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $product = Product::factory()->for($store)->create();
        $warehouse = Warehouse::query()->where('store_id', $store->id)->where('is_default', true)->firstOrFail();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['product_id' => $product->id]);
        app(TenantContext::class)->resolveToStore($store->id);
        app(InventoryService::class)->setOpeningStock($inventory, 10, 'Init', actorId: 1, idempotencyKey: 'open-'.$store->id);
        app(InventoryService::class)->reserve($inventory, 2, idempotencyKey: 'res-wh', referenceType: 'order', referenceId: 999);

        $order = Order::factory()->for($store)->create(['payment_status' => OrderPaymentStatus::Paid]);
        \App\Domain\Inventory\Models\StockReservation::query()->where('idempotency_key', 'res-wh')->update(['reference_id' => $order->id]);
        $orderItem = OrderItem::factory()->for($order)->create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 2]);

        $role = Role::factory()->for($store)->create(['slug' => 'owner']);
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $role->id, 'status' => 'active']);

        $response = $this->actingAs($owner)->postJson('/api/v1/shipments', [
            'order_id' => $order->public_id, 'warehouse_id' => $warehouse->id, 'carrier' => 'mock_courier',
            'items' => [['order_item_id' => $orderItem->id, 'quantity' => 2]],
            'idempotency_key' => 'ship-wh-1',
        ]);

        $shipment = Shipment::query()->where('public_id', $response->json('data.id'))->firstOrFail();

        return [$store, $shipment];
    }

    private function signedWebhookRequest(Store $store, array $payload, string $eventId): TestResponse
    {
        $raw = json_encode($payload);
        $signature = hash_hmac('sha256', $raw, $store->shipment_webhook_secret);

        return $this->call('POST', '/api/v1/shipment-webhooks/mock_courier', [], [], [], [
            'HTTP_X-Mock-Courier-Signature' => $signature,
            'HTTP_X-Mock-Courier-Event-Id' => $eventId,
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    public function test_correctly_signed_webhook_updates_shipment_status(): void
    {
        [$store, $shipment] = $this->labelCreatedShipment();

        $response = $this->signedWebhookRequest($store, [
            'event' => 'picked_up', 'tracking_number' => $shipment->tracking_number,
        ], 'evt-1');

        $response->assertOk();
        $this->assertSame('picked_up', $shipment->fresh()->status->value);
    }

    public function test_incorrectly_signed_webhook_is_rejected(): void
    {
        [$store, $shipment] = $this->labelCreatedShipment();
        $raw = json_encode(['event' => 'picked_up', 'tracking_number' => $shipment->tracking_number]);

        $response = $this->call('POST', '/api/v1/shipment-webhooks/mock_courier', [], [], [], [
            'HTTP_X-Mock-Courier-Signature' => 'forged',
            'HTTP_X-Mock-Courier-Event-Id' => 'evt-forged',
            'CONTENT_TYPE' => 'application/json',
        ], $raw);

        $response->assertOk(); // always 200 by design — see ShipmentWebhookController
        $this->assertSame('label_created', $shipment->fresh()->status->value);
        $this->assertDatabaseHas('shipment_webhook_events', ['external_event_id' => 'evt-forged', 'status' => 'failed']);
    }

    public function test_duplicate_webhook_event_id_is_processed_only_once(): void
    {
        [$store, $shipment] = $this->labelCreatedShipment();
        $payload = ['event' => 'picked_up', 'tracking_number' => $shipment->tracking_number];

        $this->signedWebhookRequest($store, $payload, 'evt-dup');
        $this->signedWebhookRequest($store, $payload, 'evt-dup');

        $this->assertSame(
            1,
            \App\Domain\Shipping\Models\ShipmentTrackingEvent::query()->where('shipment_id', $shipment->id)->where('source', 'webhook')->count()
        );
    }

    public function test_webhook_with_unresolvable_tracking_number_is_ignored_safely(): void
    {
        [$store, $shipment] = $this->labelCreatedShipment();

        $response = $this->signedWebhookRequest($store, ['event' => 'picked_up', 'tracking_number' => 'NONEXISTENT'], 'evt-unresolvable');

        $response->assertOk();
        $this->assertDatabaseHas('shipment_webhook_events', ['external_event_id' => 'evt-unresolvable', 'status' => 'ignored']);
        $this->assertSame('label_created', $shipment->fresh()->status->value);
    }

    public function test_full_tracking_progression_via_webhooks(): void
    {
        [$store, $shipment] = $this->labelCreatedShipment();
        $tn = $shipment->tracking_number;

        $this->signedWebhookRequest($store, ['event' => 'picked_up', 'tracking_number' => $tn], 'evt-a');
        $this->signedWebhookRequest($store, ['event' => 'in_transit', 'tracking_number' => $tn], 'evt-b');
        $this->signedWebhookRequest($store, ['event' => 'out_for_delivery', 'tracking_number' => $tn], 'evt-c');
        $this->signedWebhookRequest($store, ['event' => 'delivered', 'tracking_number' => $tn], 'evt-d');

        $fresh = $shipment->fresh();
        $this->assertSame('delivered', $fresh->status->value);
        $this->assertNotNull($fresh->delivered_at);
        $this->assertSame(4, $shipment->trackingEvents()->where('source', 'webhook')->count());
    }

    public function test_webhook_route_requires_no_sanctum_authentication(): void
    {
        [$store, $shipment] = $this->labelCreatedShipment();

        $response = $this->signedWebhookRequest($store, ['event' => 'picked_up', 'tracking_number' => $shipment->tracking_number], 'evt-no-auth');

        $this->assertNotSame(401, $response->status());
    }
}
