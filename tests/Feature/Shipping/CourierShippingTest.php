<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\ReservationStatus;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Orders\Models\Order;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentStatus;
use App\Domain\Shipping\Couriers\TestCourierAdapter;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\Models\ShipmentTrackingEvent;
use App\Domain\Shipping\Models\ShipmentWebhookEvent;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase B49 — gap G9 shipping side; Module 13 §42–56, §59, §68–75, §97–98;
 * Module 08 §21. Couriers through the Test courier (no network): accounts,
 * booking (refused, unanswered, retried), labels, tracking with back-off
 * and notifications, cash on delivery remittance, returned to sender,
 * cancel before pickup with stock back, "another courier", return pickups,
 * order reservations that no longer expire.
 */
final class CourierShippingTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    private Product $product;

    private Inventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->store = Store::factory()->create(['status' => 'active']);
        $package = Package::factory()->create();
        foreach (['orders.basic', 'payment.cod', 'payment.online', 'shipping.basic', 'products.basic'] as $key) {
            $package->entitlements()->create(['key' => $key, 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        }
        Subscription::factory()->for($this->store)->for($package)->create(['status' => SubscriptionStatus::Active]);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role_id' => $this->systemRole($this->store, 'owner')->id, 'status' => 'active']);
        app(TenantContext::class)->resolveToStore($this->store->id);
        $this->product = Product::factory()->for($this->store)->create(['name' => 'Rose Attar', 'price_minor' => 100000, 'sale_price_minor' => null, 'currency' => 'PKR', 'status' => 'active', 'visibility' => 'public']);
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $warehouse->update(['address' => '12 Industrial Area, Lahore', 'contact' => '0421234567']);
        $this->inventory = Inventory::factory()->for($this->store)->for($warehouse)->create(['product_id' => $this->product->id]);
        app(InventoryService::class)->setOpeningStock($this->inventory, 20, 'Init', actorId: $this->owner->id, idempotencyKey: 'open-'.$this->store->id);
    }

    private function asOwner(?User $user = null): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this->actingAs($user ?? $this->owner);
    }

    private function connectTestCourier(): void
    {
        $this->asOwner()->putJson('/api/v1/courier-settings/couriers/test_courier', ['environment' => 'sandbox', 'credentials' => ['account_number' => 'TC-0042', 'api_key' => 'tc-secret-key']])->assertOk();
        $this->asOwner()->putJson('/api/v1/courier-settings/couriers/test_courier/enabled', ['enabled' => true])->assertOk();
    }

    /** A COD order of $quantity units to $address. */
    private function order(int $quantity = 2, string $address = '5 Mall Road'): Order
    {
        $id = $this->asOwner()->postJson('/api/v1/orders', [
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity]], 'guest_name' => 'Ayesha Khan', 'guest_email' => 'ayesha'.uniqid().'@example.com',
            'guest_phone' => '03001234567', 'payment_method' => 'cod', 'source' => 'admin', 'idempotency_key' => 'order-'.uniqid(),
            'shipping_address' => ['name' => 'Ayesha Khan', 'line1' => $address, 'city' => 'Lahore', 'province' => 'Punjab', 'country' => 'PK'],
        ])->assertCreated()->json('data.id');

        return Order::query()->where('public_id', $id)->sole();
    }

    /** @param array<string, mixed> $extra */
    private function ship(Order $order, string $carrier = 'test_courier', array $extra = [], ?int $quantity = null): \Illuminate\Testing\TestResponse
    {
        return $this->asOwner()->postJson('/api/v1/shipments', [
            'order_id' => $order->public_id, 'warehouse_id' => $this->inventory->warehouse_id, 'carrier' => $carrier,
            'items' => [['order_item_id' => $order->items()->value('id'), 'quantity' => $quantity ?? $order->items()->value('quantity')]],
            'idempotency_key' => 'ship-'.uniqid(), 'weight_grams' => 750, ...$extra,
        ]);
    }

    private function onHand(): int
    {
        return (int) $this->inventory->fresh()->on_hand;
    }

    public function test_a_store_connects_the_test_courier_and_its_secret_never_comes_back(): void
    {
        $this->connectTestCourier();
        $settings = $this->asOwner()->getJson('/api/v1/courier-settings')->assertOk();
        $settings->assertJsonPath('data.couriers.0.account.is_enabled', true)->assertJsonPath('data.real_couriers', []);
        $this->assertEquals(['account_number' => 'TC••••42', 'api_key' => 'set'], $settings->json('data.couriers.0.account.credential_hints'));
        $this->assertStringNotContainsString('tc-secret-key', $settings->getContent());
        $this->assertStringNotContainsString('tc-secret-key', (string) DB::table('courier_accounts')->value('credentials'));

        // The tracking link must be https with {tracking}; the generic settings API cannot go round this page.
        $this->asOwner()->putJson('/api/v1/courier-settings', ['default_weight_grams' => 500, 'tracking_url_template' => 'http://track.example/{tracking}'])->assertStatus(422);
        $this->asOwner()->putJson('/api/v1/courier-settings', ['default_weight_grams' => 600, 'tracking_url_template' => 'https://track.example.pk/cn/{tracking}'])->assertOk();
        $this->asOwner()->putJson('/api/v1/store/settings/shipping.default_weight_grams', ['value' => 1])->assertForbidden()->assertJsonPath('code', 'managed_on_shipping_page');
        // Production is closed: no real courier has been checked in its sandbox yet (owner decision 2).
        $this->asOwner()->putJson('/api/v1/courier-settings/couriers/test_courier', ['environment' => 'production', 'credentials' => ['account_number' => 'X1', 'api_key' => 'y']])->assertStatus(422);
    }

    public function test_a_cod_parcel_is_booked_labelled_tracked_delivered_and_its_cash_remitted(): void
    {
        $this->connectTestCourier();
        $order = $this->order();
        $response = $this->ship($order)->assertCreated();
        $response->assertJsonPath('data.status', 'label_created')->assertJsonPath('data.booking_status', 'booked')->assertJsonPath('data.cod_status', 'pending')
            ->assertJsonPath('data.cod_amount_minor', $order->fresh()->payableMinor());
        $id = $response->json('data.id');
        $this->assertStringStartsWith('TCD', (string) $response->json('data.tracking_number'));

        // The label: a real PDF, private, audited.
        $label = $this->asOwner()->get("/api/v1/shipments/{$id}/label")->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $label->getContent());
        $this->assertSame('application/pdf', $label->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $label->headers->get('Cache-Control'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'shipping.label_downloaded']);

        // At its next scheduled read (every 15 minutes) it is delivered: every step on the way is recorded once.
        $this->travel(16)->minutes();
        $this->artisan('shipping:track')->assertSuccessful();
        $shipment = Shipment::query()->where('public_id', $id)->sole();
        $this->assertSame('delivered', $shipment->status->value);
        $this->assertSame('to_remit', $shipment->cod_status);
        $this->assertNull($shipment->next_poll_at, 'no more reads once delivered');
        $count = ShipmentTrackingEvent::query()->where('shipment_id', $shipment->id)->count();
        $this->asOwner()->postJson("/api/v1/shipments/{$id}/refresh")->assertOk();
        $this->assertSame($count, ShipmentTrackingEvent::query()->where('shipment_id', $shipment->id)->count(), 'events are recorded once');
        $this->assertSame('fulfilled', $order->fresh()->fulfillment_status->value);

        // The customer sees status and events, not the store's courier data.
        $customerView = (new \App\Domain\Shipping\Http\Resources\CustomerShipmentResource($shipment->load('trackingEvents')))->resolve();
        $this->assertArrayNotHasKey('cod_status', $customerView);
        $this->assertArrayNotHasKey('booking_error', $customerView);
        $this->assertSame('Delivered', end($customerView['tracking_events'])['description']);

        // The courier paid over the cash: recorded on the order's payment once.
        $this->asOwner()->getJson('/api/v1/shipments?view=cod')->assertOk()->assertJsonCount(1, 'data');
        $this->asOwner()->postJson('/api/v1/shipments/cod-remittance', ['shipment_ids' => [$id], 'reference' => 'TC-REMIT-0001'])->assertOk()->assertJsonPath('data.recorded', 1);
        $this->asOwner()->postJson('/api/v1/shipments/cod-remittance', ['shipment_ids' => [$id], 'reference' => 'TC-REMIT-0001'])->assertOk()->assertJsonPath('data.recorded', 0);
        $this->assertSame(PaymentStatus::Paid, Payment::query()->where('order_id', $order->id)->sole()->status);
        $this->assertSame('remitted', $shipment->fresh()->cod_status);
    }

    public function test_a_refused_or_unanswered_booking_waits_for_a_person_and_can_be_sent_again(): void
    {
        $this->connectTestCourier();
        $refused = $this->ship($this->order(1, 'NOBOOK Lane'))->assertCreated();
        $refused->assertJsonPath('data.status', 'ready')->assertJsonPath('data.booking_status', 'failed')->assertJsonPath('data.actions.book', true);
        $this->assertStringContainsString('does not serve', (string) $refused->json('data.booking_error'));

        $silent = $this->ship($this->order(1, 'TIMEOUT Street'))->assertCreated()->assertJsonPath('data.booking_status', 'unknown');
        $this->asOwner()->getJson('/api/v1/shipments?view=attention')->assertOk()->assertJsonCount(2, 'data');

        // Without a warehouse address no courier is asked at all.
        Warehouse::query()->where('is_default', true)->update(['address' => null]);
        $noAddress = $this->ship($this->order(1))->assertCreated()->assertJsonPath('data.booking_status', 'failed');
        $this->assertStringContainsString('warehouse', (string) $noAddress->json('data.booking_error'));
        Warehouse::query()->where('is_default', true)->update(['address' => '12 Industrial Area, Lahore']);
        $this->asOwner()->postJson('/api/v1/shipments/'.$noAddress->json('data.id').'/book')->assertOk()->assertJsonPath('data.booking_status', 'booked')->assertJsonPath('data.status', 'label_created');
        $this->asOwner()->postJson('/api/v1/shipments/'.$noAddress->json('data.id').'/book')->assertStatus(409);
        $this->assertNotNull($silent->json('data.id'));
    }

    public function test_cancelling_before_pickup_puts_the_units_back_for_the_order_and_after_pickup_is_refused(): void
    {
        $this->connectTestCourier();
        $order = $this->order(2);
        $this->assertSame(20, $this->onHand());
        $first = $this->ship($order)->assertCreated()->json('data.id');
        $this->assertSame(18, $this->onHand());

        $this->asOwner()->postJson("/api/v1/shipments/{$first}/cancel", ['note' => 'Customer changed the address'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(20, $this->onHand(), 'the units are back on the shelf');
        $this->assertSame(2, (int) StockReservation::query()->where('reference_type', 'order')->where('reference_id', $order->id)->where('status', ReservationStatus::Active)->sum('quantity'), 'still held for the order');
        $this->assertSame('unfulfilled', $order->fresh()->fulfillment_status->value);

        // The order can be shipped again: the cancelled shipment ships nothing.
        $second = $this->ship($order)->assertCreated()->json('data.id');
        $this->assertSame(18, $this->onHand());
        $this->travel(2)->minutes();
        $this->asOwner()->postJson("/api/v1/shipments/{$second}/refresh")->assertOk()->assertJsonPath('data.status', 'in_transit');
        // The courier has it: it refuses the cancellation, and so nothing is cancelled here.
        $this->asOwner()->postJson("/api/v1/shipments/{$second}/cancel")->assertStatus(409)->assertJsonPath('code', 'cancel_refused');
        $this->assertSame('in_transit', Shipment::query()->where('public_id', $second)->sole()->status->value);
    }

    public function test_a_parcel_returned_to_sender_goes_back_in_stock_once_and_collects_no_cash(): void
    {
        $this->connectTestCourier();
        $id = $this->ship($this->order(3, '9 RTO Road'))->assertCreated()->json('data.id');
        $this->assertSame(17, $this->onHand());
        $this->travel(16)->minutes();
        $this->artisan('shipping:track')->assertSuccessful();
        $shipment = Shipment::query()->where('public_id', $id)->sole();
        $this->assertSame('returned', $shipment->status->value);
        $this->assertSame('none', $shipment->cod_status);
        $this->asOwner()->getJson('/api/v1/shipments?view=attention')->assertJsonCount(1, 'data')->assertJsonPath('data.0.actions.restock', true);

        $this->asOwner()->postJson("/api/v1/shipments/{$id}/restock")->assertOk()->assertJsonPath('data.actions.restock', false);
        $this->assertSame(20, $this->onHand());
        $this->asOwner()->postJson("/api/v1/shipments/{$id}/restock")->assertStatus(409);
        $this->assertSame(20, $this->onHand());
    }

    public function test_another_courier_takes_a_typed_number_and_the_customer_gets_the_stores_tracking_link(): void
    {
        $this->asOwner()->putJson('/api/v1/courier-settings', ['default_weight_grams' => 500, 'tracking_url_template' => 'https://track.example.pk/cn/{tracking}'])->assertOk();
        $order = $this->order(1);
        $this->ship($order, 'manual_courier', ['carrier_name' => ''])->assertStatus(422)->assertJsonValidationErrors('carrier_name');
        $response = $this->ship($order, 'manual_courier', ['carrier_name' => 'Leopards'])->assertCreated()->assertJsonPath('data.status', 'ready');
        $id = $response->json('data.id');
        $this->asOwner()->putJson("/api/v1/shipments/{$id}/tracking", ['carrier_name' => 'Leopards', 'tracking_number' => 'LE7788990011'])->assertOk()
            ->assertJsonPath('data.status', 'label_created')->assertJsonPath('data.carrier_label', 'Leopards')
            ->assertJsonPath('data.tracking_url', 'https://track.example.pk/cn/LE7788990011');
        // One number per parcel in a store.
        $other = $this->ship($this->order(1), 'manual_courier', ['carrier_name' => 'TCS'])->json('data.id');
        $this->asOwner()->putJson("/api/v1/shipments/{$other}/tracking", ['carrier_name' => 'TCS', 'tracking_number' => 'LE7788990011'])->assertStatus(422);
        $label = $this->asOwner()->get("/api/v1/shipments/{$id}/label")->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $label->getContent());
    }

    public function test_a_courier_notification_makes_the_engine_read_tracking_and_forged_or_old_ones_do_nothing(): void
    {
        $this->connectTestCourier();
        $response = $this->ship($this->order(1))->assertCreated();
        $tracking = (string) $response->json('data.tracking_number');
        $this->travel(2)->minutes();
        $notify = function (string $signature, int $time) use ($tracking) {
            $raw = (string) json_encode(['tracking_number' => $tracking, 'n' => uniqid()]);

            return $this->call('POST', '/api/v1/shipment-webhooks/test_courier', [], [], [], [
                'HTTP_X-Test-Courier-Signature' => $signature === 'valid' ? TestCourierAdapter::sign((string) $time, $raw, 'tc-secret-key') : $signature,
                'HTTP_X-Test-Courier-Timestamp' => (string) $time, 'CONTENT_TYPE' => 'application/json',
            ], $raw);
        };

        $notify('forged', now()->getTimestamp())->assertOk()->assertJsonPath('status', 'failed');
        $notify('valid', now()->subMinutes(10)->getTimestamp())->assertOk()->assertJsonPath('status', 'failed');
        $this->assertSame('label_created', Shipment::query()->where('tracking_number', $tracking)->sole()->status->value);
        $notify('valid', now()->getTimestamp())->assertOk()->assertJsonPath('status', 'processed');
        $this->assertSame('in_transit', Shipment::query()->where('tracking_number', $tracking)->sole()->status->value);
        $this->assertSame(['tracking_number' => $tracking], ShipmentWebhookEvent::query()->latest('id')->first()->payload, 'only the tracking number is kept');
    }

    public function test_a_return_pickup_moves_the_return_on_its_way_when_the_courier_collects_it(): void
    {
        $this->connectTestCourier();
        $order = $this->order(1);
        $id = $this->ship($order, 'store_pickup')->json('data.id');
        foreach (['picked_up', 'delivered'] as $status) {
            $this->asOwner()->postJson("/api/v1/shipments/{$id}/status", ['status' => $status])->assertOk();
        }
        $return = $this->asOwner()->postJson("/api/v1/orders/{$order->public_id}/returns", ['items' => [['order_item_id' => $order->items()->value('id'), 'quantity' => 1]], 'resolution' => 'refund', 'reason' => 'defective', 'description' => 'Leaks', 'idempotency_key' => 'r-'.uniqid()])->assertCreated()->json('data.id');
        $this->asOwner()->postJson("/api/v1/returns/{$return}/shipments", ['carrier' => 'test_courier'])->assertStatus(409); // not approved yet
        $this->asOwner()->postJson("/api/v1/returns/{$return}/approve", ['return_method' => 'carrier_pickup', 'return_shipping_paid_by' => 'store'])->assertOk();

        $parcel = $this->asOwner()->postJson("/api/v1/returns/{$return}/shipments", ['carrier' => 'test_courier'])->assertCreated();
        $parcel->assertJsonPath('data.direction', 'return')->assertJsonPath('data.booking_status', 'booked')->assertJsonPath('data.cod_amount_minor', 0);
        $this->asOwner()->postJson("/api/v1/returns/{$return}/shipments", ['carrier' => 'test_courier'])->assertStatus(409);
        $onHand = $this->onHand();

        $this->travel(2)->minutes();
        $this->asOwner()->postJson('/api/v1/shipments/'.$parcel->json('data.id').'/refresh')->assertOk();
        $this->asOwner()->getJson("/api/v1/returns/{$return}")->assertOk()->assertJsonPath('data.status', 'in_transit');
        $this->assertSame($onHand, $this->onHand(), 'a return parcel moves no stock; the inspection does');
        $this->assertSame('fulfilled', $order->fresh()->fulfillment_status->value);
    }

    public function test_stock_held_for_a_placed_order_no_longer_expires(): void
    {
        $order = $this->order(4);
        $this->travel(1)->days();
        $this->artisan('inventory:expire-reservations')->assertSuccessful();
        $this->assertSame(4, (int) StockReservation::query()->where('reference_type', 'order')->where('reference_id', $order->id)->where('status', ReservationStatus::Active)->sum('quantity'));
        // Shipping later still takes the units off the shelf (before B49 it took nothing).
        $this->ship($order, 'local_delivery')->assertCreated();
        $this->assertSame(16, $this->onHand());
    }

    public function test_labels_and_courier_settings_need_the_right_people_and_stay_in_their_store(): void
    {
        $this->connectTestCourier();
        $id = $this->ship($this->order(1))->assertCreated()->json('data.id');
        $viewer = $this->member(['shipments.view']);
        $this->asOwner($viewer)->get("/api/v1/shipments/{$id}/label")->assertForbidden();
        $this->asOwner($viewer)->putJson('/api/v1/courier-settings/couriers/test_courier/enabled', ['enabled' => false])->assertForbidden();
        $this->asOwner($viewer)->postJson('/api/v1/shipments/cod-remittance', ['shipment_ids' => [$id], 'reference' => 'x'])->assertForbidden();

        $other = Store::factory()->create(['status' => 'active']);
        $stranger = User::factory()->create();
        $other->users()->attach($stranger, ['role_id' => $this->systemRole($other, 'owner')->id, 'status' => 'active']);
        $this->asOwner($stranger)->get("/api/v1/shipments/{$id}/label")->assertNotFound();
        $this->asOwner($stranger)->getJson('/api/v1/courier-settings')->assertOk()->assertJsonPath('data.couriers.0.account', null);
    }

    /** @param list<string> $permissions */
    private function member(array $permissions): User
    {
        $role = Role::factory()->for($this->store)->create();
        foreach ($permissions as $key) {
            $role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['key' => $key], ['group' => 'shipping'])->id]);
        }
        $user = User::factory()->create();
        $this->store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }
}
