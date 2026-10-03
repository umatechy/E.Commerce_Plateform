<?php

declare(strict_types=1);

namespace Tests\Feature\Returns;

use App\Domain\Catalog\Models\Product;
use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Payments\Models\Payment;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase B33 (gap G8, Module 09 §45–54, Module 13 §70): returns from the
 * request to the refund or the replacement order — quantities, stock,
 * money, permissions and tenant isolation.
 */
final class ReturnFlowTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private User $owner;
    private Product $product;
    private Inventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create(['status' => 'active']);
        $this->entitle($this->store, ['orders.basic', 'payment.cod', 'payment.bank_transfer', 'shipping.basic', 'products.basic']);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role_id' => $this->systemRole($this->store, 'owner')->id, 'status' => 'active']);
        app(TenantContext::class)->resolveToStore($this->store->id);

        // Rs. 1,000 each, 20 in stock.
        $this->product = Product::factory()->for($this->store)->create(['name' => 'Rose Attar', 'price_minor' => 100000, 'sale_price_minor' => null, 'currency' => 'PKR', 'status' => 'active']);
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->inventory = Inventory::factory()->for($this->store)->for($warehouse)->create(['product_id' => $this->product->id]);
        app(InventoryService::class)->setOpeningStock($this->inventory, 20, 'Init', actorId: $this->owner->id, idempotencyKey: 'open-'.$this->store->id);
    }

    /** @param list<string> $keys */
    private function staffWith(array $keys): User
    {
        $role = Role::factory()->for($this->store)->create();
        foreach ($keys as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['group' => 'returns', 'description' => 'x']);
            DB::table('permission_role')->insert(['role_id' => $role->id, 'permission_id' => $permission->id]);
        }
        $user = User::factory()->create();
        $this->store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    /** An order of `$quantity` units, delivered, and paid unless told otherwise. */
    private function deliveredOrder(int $quantity = 3, ?Customer $customer = null, bool $paid = true, bool $deliver = true): Order
    {
        static $n = 0;
        $n++;
        $who = $customer !== null ? ['customer' => $customer->public_id] : ['guest_name' => 'Guest Buyer', 'guest_email' => "guest{$n}@example.com"];
        $response = $this->actingAs($this->owner)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity]], ...$who,
            'payment_method' => 'cod', 'source' => 'admin', 'idempotency_key' => "order-{$n}-".uniqid(),
        ])->assertCreated();
        $order = Order::query()->where('public_id', $response->json('data.id'))->sole();

        if ($deliver) {
            $shipment = $this->actingAs($this->owner)->postJson('/api/v1/shipments', [
                'order_id' => $order->public_id, 'warehouse_id' => $this->inventory->warehouse_id, 'carrier' => 'store_pickup',
                'items' => [['order_item_id' => $order->items()->value('id'), 'quantity' => $quantity]], 'idempotency_key' => "ship-{$n}-".uniqid(),
            ])->assertCreated()->json('data.id');
            foreach (['picked_up', 'delivered'] as $status) { // a new shipment is "ready"
                $this->actingAs($this->owner)->postJson("/api/v1/shipments/{$shipment}/status", ['status' => $status])->assertSuccessful();
            }
        }
        if ($paid) {
            $payment = Payment::query()->where('order_id', $order->id)->sole();
            $this->actingAs($this->owner)->postJson("/api/v1/payments/{$payment->id}/manual-confirm", ['amount_minor' => $order->grand_total_minor, 'reference' => 'CASH'])->assertCreated();
        }

        return $order->fresh();
    }

    /** @return array<string, mixed> */
    private function body(Order $order, int $quantity, string $resolution = 'refund'): array
    {
        return [
            'items' => [['order_item_id' => $order->items()->value('id'), 'quantity' => $quantity]],
            'resolution' => $resolution, 'reason' => 'defective', 'description' => 'Leaks', 'idempotency_key' => 'ret-'.uniqid(),
        ];
    }

    /** Opens a return and takes it to "inspected". */
    private function inspected(Order $order, int $quantity, int $resalable, int $damaged, int $rejected, string $resolution = 'refund'): string
    {
        $id = $this->actingAs($this->owner)->postJson("/api/v1/orders/{$order->public_id}/returns", $this->body($order, $quantity, $resolution))->assertCreated()->json('data.id');
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/approve", ['return_method' => 'drop_off', 'return_shipping_paid_by' => 'store'])->assertOk();
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/receive")->assertOk()->assertJsonPath('data.status', 'received');
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/inspect", ['items' => [[
            'order_item_id' => $order->items()->value('id'), 'resalable' => $resalable, 'damaged' => $damaged, 'rejected' => $rejected, 'note' => 'Checked',
        ]]])->assertOk();

        return $id;
    }

    public function test_a_return_goes_from_request_to_refund_with_stock_and_money_right(): void
    {
        $order = $this->deliveredOrder(3);
        $this->assertSame(17, $this->inventory->fresh()->on_hand, 'three units left the shelf with the shipment');

        $this->actingAs($this->owner)->getJson("/api/v1/orders/{$order->public_id}/returnable")->assertOk()
            ->assertJsonPath('data.lines.0.delivered', 3)->assertJsonPath('data.lines.0.returnable', 3);

        $id = $this->actingAs($this->owner)->postJson("/api/v1/orders/{$order->public_id}/returns", $this->body($order, 2))
            ->assertCreated()->assertJsonPath('data.status', 'requested')->assertJsonPath('data.return_number', "R-{$order->order_number}-1")->json('data.id');
        $this->assertSame('return_requested', $order->fresh()->return_status->value);

        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/review")->assertOk()->assertJsonPath('data.status', 'under_review');
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/approve", ['note' => 'Please bring it to the shop', 'return_method' => 'drop_off', 'return_shipping_paid_by' => 'customer'])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.return_method', 'drop_off');
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/in-transit", ['carrier' => 'TCS', 'tracking_number' => 'TCS123'])->assertOk()->assertJsonPath('data.status', 'in_transit');
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/receive")->assertOk();
        // Nothing is counted into stock before the inspection.
        $this->assertSame(17, $this->inventory->fresh()->on_hand);

        $itemId = $order->items()->value('id');
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/inspect", ['items' => [['order_item_id' => $itemId, 'resalable' => 1, 'damaged' => 1, 'rejected' => 1]]])
            ->assertUnprocessable(); // must add up to 2
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/inspect", ['items' => [['order_item_id' => $itemId, 'resalable' => 1, 'damaged' => 1, 'rejected' => 0]]])
            ->assertOk()->assertJsonPath('data.status', 'inspected')->assertJsonPath('data.items_refund_minor', 200000);

        // One unit is sellable again; the damaged one came in and was written off.
        $this->assertSame(18, $this->inventory->fresh()->on_hand);
        $movements = StockMovement::query()->where('reference_type', 'return')->orderBy('id')->get()->map(fn ($m) => [$m->type->value, $m->quantity])->all();
        $this->assertSame([['return_in', 1], ['return_in', 1], ['damage_out', -1]], $movements);
        $this->assertSame('partially_returned', $order->fresh()->return_status->value);

        // The fee cannot exceed the items; the order had no shipping to refund.
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/approve-refund", ['shipping_refund_minor' => 1])->assertUnprocessable()->assertJsonValidationErrors('shipping_refund_minor');
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/approve-refund", ['restocking_fee_minor' => 200001])->assertUnprocessable()->assertJsonValidationErrors('restocking_fee_minor');
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/approve-refund", ['restocking_fee_minor' => 10000])
            ->assertOk()->assertJsonPath('data.status', 'approved_for_refund')->assertJsonPath('data.refund_total_minor', 190000);

        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/refund")->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.refunded_minor', 190000);
        // A repeat pays nothing twice (§51).
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/refund")->assertStatus(409);

        $payment = Payment::query()->where('order_id', $order->id)->sole();
        $this->assertSame('partially_refunded', $payment->status->value);
        $this->assertSame(300000 - 190000, $payment->refundableAmountMinor());
        $this->assertSame('partially_refunded', $order->fresh()->payment_status->value);
        // The order itself was never edited.
        $this->assertSame(300000, $order->fresh()->grand_total_minor);
        $this->assertSame('confirmed', $order->fresh()->status->value);

        $actions = AuditLog::query()->where('subject_type', 'return_request')->pluck('action')->all();
        foreach (['return.requested', 'return.approved', 'return.received', 'return.inspected', 'return.refund_approved', 'return.refunded'] as $action) {
            $this->assertContains($action, $actions);
        }
        $timeline = DB::table('order_timeline_events')->where('order_id', $order->id)->pluck('event_type')->all();
        $this->assertContains('return_requested', $timeline);
        $this->assertContains('return_refunded', $timeline);
    }

    public function test_a_unit_can_be_returned_once_and_only_after_delivery(): void
    {
        $undelivered = $this->deliveredOrder(2, deliver: false);
        $this->actingAs($this->owner)->postJson("/api/v1/orders/{$undelivered->public_id}/returns", $this->body($undelivered, 1))
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');

        $order = $this->deliveredOrder(3);
        $this->actingAs($this->owner)->postJson("/api/v1/orders/{$order->public_id}/returns", $this->body($order, 4))->assertUnprocessable();
        $first = $this->actingAs($this->owner)->postJson("/api/v1/orders/{$order->public_id}/returns", $this->body($order, 2))->assertCreated()->json('data.id');
        $this->actingAs($this->owner)->postJson("/api/v1/orders/{$order->public_id}/returns", $this->body($order, 2))->assertUnprocessable();
        $this->actingAs($this->owner)->postJson("/api/v1/orders/{$order->public_id}/returns", $this->body($order, 1))->assertCreated()->assertJsonPath('data.return_number', "R-{$order->order_number}-2");
        $this->actingAs($this->owner)->postJson("/api/v1/orders/{$order->public_id}/returns", $this->body($order, 1))->assertUnprocessable();

        // A rejected request gives its units back.
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$first}/reject", [])->assertUnprocessable();
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$first}/reject", ['note' => 'Used item'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->actingAs($this->owner)->getJson("/api/v1/orders/{$order->public_id}/returnable")->assertJsonPath('data.lines.0.returnable', 2);

        // The same request sent twice is one return.
        $body = $this->body($order, 1);
        $a = $this->actingAs($this->owner)->postJson("/api/v1/orders/{$order->public_id}/returns", $body)->assertCreated()->json('data.id');
        $b = $this->actingAs($this->owner)->postJson("/api/v1/orders/{$order->public_id}/returns", $body)->json('data.id');
        $this->assertSame($a, $b);

        // An item of another order is not an item of this one.
        $other = $this->deliveredOrder(1);
        $this->actingAs($this->owner)->postJson("/api/v1/orders/{$order->public_id}/returns", [...$this->body($order, 1), 'items' => [['order_item_id' => $other->items()->value('id'), 'quantity' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.order_item_id');
    }

    public function test_steps_cannot_be_skipped(): void
    {
        $order = $this->deliveredOrder(2);
        $id = $this->actingAs($this->owner)->postJson("/api/v1/orders/{$order->public_id}/returns", $this->body($order, 1))->json('data.id');
        $itemId = $order->items()->value('id');

        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/receive")->assertStatus(409)->assertJsonPath('code', 'invalid_return_transition');
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/inspect", ['items' => [['order_item_id' => $itemId, 'resalable' => 1, 'damaged' => 0, 'rejected' => 0]]])->assertStatus(409);
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/approve-refund")->assertStatus(409);
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/refund")->assertStatus(409);
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/replacement", ['resolution' => 'replacement'])->assertStatus(409);
        $this->assertSame(18, $this->inventory->fresh()->on_hand);

        // Once the goods are in, the request can no longer be withdrawn.
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/approve")->assertOk();
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/receive")->assertOk();
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/cancel")->assertStatus(409);
    }

    public function test_nothing_accepted_ends_the_return_as_rejected_without_stock_or_money(): void
    {
        $order = $this->deliveredOrder(2);
        $id = $this->inspected($order, 2, 0, 0, 2);

        $this->actingAs($this->owner)->getJson("/api/v1/returns/{$id}")->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.items_refund_minor', 0);
        $this->assertSame(18, $this->inventory->fresh()->on_hand);
        $this->assertSame('none', $order->fresh()->return_status->value);
        $this->assertSame(0, StockMovement::query()->where('reference_type', 'return')->count());
    }

    public function test_an_unpaid_order_returns_no_money(): void
    {
        $order = $this->deliveredOrder(1, paid: false);
        $id = $this->inspected($order, 1, 1, 0, 0);

        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/approve-refund")->assertOk()->assertJsonPath('data.refund_total_minor', 100000);
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/refund")->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.refunded_minor', 0);
        $this->assertSame('returned', $order->fresh()->return_status->value);
    }

    public function test_permissions_are_separate_and_another_store_sees_nothing(): void
    {
        $order = $this->deliveredOrder(2);
        $viewer = $this->staffWith(['returns.view']);
        $clerk = $this->staffWith(['returns.view', 'returns.manage']);
        $approver = $this->staffWith(['returns.view', 'returns.manage', 'returns.approve']);
        $nobody = $this->staffWith(['orders.view']);

        $this->actingAs($nobody)->getJson('/api/v1/returns')->assertForbidden();
        $this->actingAs($viewer)->getJson('/api/v1/returns')->assertOk();
        $this->actingAs($viewer)->postJson("/api/v1/orders/{$order->public_id}/returns", $this->body($order, 1))->assertForbidden();

        $id = $this->actingAs($clerk)->postJson("/api/v1/orders/{$order->public_id}/returns", $this->body($order, 1))->assertCreated()->json('data.id');
        $this->actingAs($clerk)->postJson("/api/v1/returns/{$id}/approve")->assertForbidden();
        $this->actingAs($approver)->postJson("/api/v1/returns/{$id}/approve")->assertOk();
        $this->actingAs($clerk)->postJson("/api/v1/returns/{$id}/receive")->assertOk();
        $this->actingAs($clerk)->postJson("/api/v1/returns/{$id}/inspect", ['items' => [['order_item_id' => $order->items()->value('id'), 'resalable' => 1, 'damaged' => 0, 'rejected' => 0]]])->assertOk();
        $this->actingAs($clerk)->postJson("/api/v1/returns/{$id}/approve-refund")->assertForbidden();
        $this->actingAs($approver)->postJson("/api/v1/returns/{$id}/approve-refund")->assertOk();
        // Deciding a refund and paying it are different permissions.
        $this->actingAs($approver)->postJson("/api/v1/returns/{$id}/refund")->assertForbidden();
        $this->assertSame(0, ReturnRequest::query()->sole()->refunded_minor);

        // Another store.
        $otherStore = Store::factory()->create();
        $otherOwner = User::factory()->create();
        $otherStore->users()->attach($otherOwner, ['role_id' => $this->systemRole($otherStore, 'owner')->id, 'status' => 'active']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($otherOwner)->getJson("/api/v1/returns/{$id}")->assertNotFound();
        $this->actingAs($otherOwner)->postJson("/api/v1/returns/{$id}/refund")->assertNotFound();
        $this->actingAs($otherOwner)->getJson("/api/v1/orders/{$order->public_id}/returnable")->assertNotFound();
        $this->actingAs($otherOwner)->getJson('/api/v1/returns')->assertOk()->assertJsonCount(0, 'data.data');
    }

    public function test_a_replacement_order_is_free_linked_and_made_once(): void
    {
        $order = $this->deliveredOrder(2);
        $id = $this->inspected($order, 2, 1, 1, 0, 'replacement');

        $response = $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/replacement", ['resolution' => 'replacement'])->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.replacement_order.grand_total_minor', 0);
        $new = Order::query()->where('public_id', $response->json('data.replacement_order.id'))->sole();

        $this->assertSame($order->id, $new->replacement_for_order_id);
        $this->assertSame('replacement', $new->source->value);
        $this->assertSame(2, (int) $new->items()->sum('quantity'));
        $this->assertSame('paid', Payment::query()->where('order_id', $new->id)->sole()->status->value, 'a free order is settled at once and can be shipped');
        $this->assertSame($order->guest_email, $new->guest_email);
        // The original payment keeps its money: nothing was refunded.
        $this->assertSame('paid', Payment::query()->where('order_id', $order->id)->sole()->status->value);
        $this->assertSame(2, $this->inventory->fresh()->reserved, 'the replacement reserved its stock');

        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/replacement", ['resolution' => 'replacement'])->assertStatus(409);
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/approve-refund")->assertStatus(409);
        $this->assertSame(1, Order::query()->where('replacement_for_order_id', $order->id)->count());
    }

    public function test_an_exchange_counts_the_returned_value_and_settles_the_difference(): void
    {
        $cheap = Product::factory()->for($this->store)->create(['name' => 'Sample vial', 'price_minor' => 30000, 'sale_price_minor' => null, 'currency' => 'PKR', 'status' => 'active']);
        $dear = Product::factory()->for($this->store)->create(['name' => 'Oud', 'price_minor' => 250000, 'sale_price_minor' => null, 'currency' => 'PKR', 'status' => 'active']);
        foreach ([$cheap, $dear] as $product) {
            $inventory = Inventory::factory()->for($this->store)->create(['warehouse_id' => $this->inventory->warehouse_id, 'product_id' => $product->id]);
            app(InventoryService::class)->setOpeningStock($inventory, 5, 'Init', actorId: $this->owner->id, idempotencyKey: 'open-p'.$product->id);
        }

        // Cheaper: Rs. 1,000 returned, Rs. 300 taken, Rs. 700 left to refund.
        $order = $this->deliveredOrder(1);
        $id = $this->inspected($order, 1, 1, 0, 0, 'exchange');
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/replacement", ['resolution' => 'exchange'])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/replacement", ['resolution' => 'exchange', 'items' => [['product_id' => $cheap->id, 'quantity' => 1]]])
            ->assertOk()->assertJsonPath('data.status', 'inspected')->assertJsonPath('data.replacement_order.grand_total_minor', 0)->assertJsonPath('data.items_refund_minor', 70000);
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/approve-refund")->assertOk()->assertJsonPath('data.refund_total_minor', 70000)->assertJsonPath('data.resolution', 'exchange');
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/refund")->assertOk()->assertJsonPath('data.refunded_minor', 70000);

        // Dearer: Rs. 1,000 returned, Rs. 2,500 chosen, Rs. 1,500 to pay — by a named method.
        $order2 = $this->deliveredOrder(1);
        $id2 = $this->inspected($order2, 1, 1, 0, 0, 'exchange');
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id2}/replacement", ['resolution' => 'exchange', 'items' => [['product_id' => $dear->id, 'quantity' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $response = $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id2}/replacement", ['resolution' => 'exchange', 'items' => [['product_id' => $dear->id, 'quantity' => 1]], 'payment_method' => 'cod'])
            ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.replacement_order.grand_total_minor', 150000);
        $new = Order::query()->where('public_id', $response->json('data.replacement_order.id'))->sole();
        $this->assertSame(100000, $new->discount_total_minor);
        $this->assertSame(150000, Payment::query()->where('order_id', $new->id)->sole()->amount_minor);
    }

    public function test_the_refund_follows_what_was_paid_including_an_order_discount(): void
    {
        // 3 × Rs. 1,000 with Rs. 300 off the whole order: each unit was paid Rs. 900.
        $order = app(\App\Domain\Orders\Services\OrderService::class)->createOrder(
            [['product_id' => $this->product->id, 'quantity' => 3]],
            ['guest_name' => 'D', 'guest_email' => 'd@example.com', 'discount_total_minor' => 30000, 'shipping_total_minor' => 25000], 'disc-1',
        );
        $item = OrderItem::query()->where('order_id', $order->id)->sole();
        $calculator = app(\App\Domain\Returns\Services\RefundCalculator::class);

        $this->assertSame([$item->id => 90000], $calculator->forItems($order, [$item->id => 1]));
        $this->assertSame([$item->id => 270000], $calculator->forItems($order, [$item->id => 3]));
        // Shipping is never part of the items amount.
        $this->assertSame(295000, $order->grand_total_minor);

        // Odd amounts round down per part, and the whole line returns the whole amount.
        $order->update(['discount_total_minor' => 10000]); // 290,000 paid for 3
        $this->assertSame([$item->id => 96666], $calculator->forItems($order->fresh(), [$item->id => 1]));
        $this->assertSame([$item->id => 290000], $calculator->forItems($order->fresh(), [$item->id => 3]));
    }

    public function test_a_customer_asks_for_their_own_return_when_the_store_allows_it(): void
    {
        $customer = Customer::factory()->for($this->store)->create(['password' => Hash::make('correct-horse-99')]);
        $stranger = Customer::factory()->for($this->store)->create(['password' => Hash::make('correct-horse-99')]);
        $order = $this->deliveredOrder(2, $customer);
        $headers = fn (Customer $c) => ['Authorization' => 'Bearer '.$c->createToken('t')->plainTextToken, 'X-Store-Slug' => $this->store->slug];
        $body = $this->body($order, 1);
        $this->app['auth']->forgetGuards();

        // Off until the store decides (Module 09 §45: "where the store allows them").
        $this->getJson("/api/v1/customer/orders/{$order->public_id}/returnable", $headers($customer))->assertOk()->assertJsonPath('data.enabled', false);
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/customer/orders/{$order->public_id}/returns", $body, $headers($customer))->assertForbidden()->assertJsonPath('code', 'returns_by_contact_only');

        $this->storeSetting('returns.customer_requests_enabled', true);
        $this->app['auth']->forgetGuards();
        // Another customer's order is not found.
        $this->postJson("/api/v1/customer/orders/{$order->public_id}/returns", $body, $headers($stranger))->assertNotFound();
        $this->app['auth']->forgetGuards();
        $id = $this->postJson("/api/v1/customer/orders/{$order->public_id}/returns", $body, $headers($customer))
            ->assertCreated()->assertJsonPath('data.requested_by', 'customer')->json('data.id');

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/customer/returns/{$id}", $headers($stranger))->assertNotFound();
        $this->app['auth']->forgetGuards();
        // The parcel is sent only after the store approved.
        $this->postJson("/api/v1/customer/returns/{$id}/shipped", ['tracking_number' => 'X1'], $headers($customer))->assertStatus(409)->assertJsonPath('code', 'not_approved');

        // (Staff act through the service here: one test cannot switch between the customer token and the staff session.)
        $returns = app(\App\Domain\Returns\Services\ReturnService::class);
        $returns->approve(ReturnRequest::query()->where('public_id', $id)->sole(), ['note' => 'Send it to our Lahore shop'], $this->owner);
        $this->app['auth']->forgetGuards();
        $view = $this->postJson("/api/v1/customer/returns/{$id}/shipped", ['carrier' => 'Leopards', 'tracking_number' => 'LP1'], $headers($customer))
            ->assertOk()->assertJsonPath('data.status', 'in_transit')->assertJsonPath('data.decision_note', 'Send it to our Lahore shop');
        // The customer's view has no staff-only fields.
        $this->assertArrayNotHasKey('inspection_note', $view->json('data.items.0'));
        $this->assertArrayNotHasKey('warehouse', $view->json('data'));

        // Outside the return period the customer is sent to the store; staff still can.
        DB::table('shipments')->where('order_id', $order->id)->update(['delivered_at' => now()->subDays(30)]);
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/customer/orders/{$order->public_id}/returns", $this->body($order, 1), $headers($customer))->assertUnprocessable();
        $late = $this->body($order, 1);
        $this->assertSame('staff', $returns->request($order, $late['items'], $late, $this->owner, 'late-1')->requested_by);
    }

    private function storeSetting(string $key, mixed $value): void
    {
        DB::table('store_settings')->updateOrInsert(['store_id' => $this->store->id, 'key' => $key], ['value' => json_encode([$value]), 'created_at' => now(), 'updated_at' => now()]);
        \Illuminate\Support\Facades\Cache::forget("settings:store:{$this->store->id}:{$key}");
    }
}
