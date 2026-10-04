<?php

declare(strict_types=1);

namespace Tests\Feature\Returns;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Models\Payment;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Shared set-up of the return tests (Phases B33–B34): a store with an
 * owner and a product at Rs. 1,000 with 20 in stock, and helpers that
 * make a delivered, paid order and take a return to "inspected" through
 * the real APIs.
 */
trait BuildsReturns
{
    protected Store $store;
    protected User $owner;
    protected Product $product;
    protected Inventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create(['status' => 'active']);
        $this->entitle($this->store, ['orders.basic', 'payment.cod', 'payment.bank_transfer', 'shipping.basic', 'products.basic', 'customers.advanced']);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role_id' => $this->systemRole($this->store, 'owner')->id, 'status' => 'active']);
        app(TenantContext::class)->resolveToStore($this->store->id);

        // Rs. 1,000 each, 20 in stock.
        $this->product = Product::factory()->for($this->store)->create(['name' => 'Rose Attar', 'price_minor' => 100000, 'sale_price_minor' => null, 'currency' => 'PKR', 'status' => 'active', 'visibility' => 'public']);
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->inventory = Inventory::factory()->for($this->store)->for($warehouse)->create(['product_id' => $this->product->id]);
        app(InventoryService::class)->setOpeningStock($this->inventory, 20, 'Init', actorId: $this->owner->id, idempotencyKey: 'open-'.$this->store->id);
    }

    /** @param list<string> $keys */
    protected function staffWith(array $keys): User
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
    protected function deliveredOrder(int $quantity = 3, ?Customer $customer = null, bool $paid = true, bool $deliver = true): Order
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
    protected function body(Order $order, int $quantity, string $resolution = 'refund'): array
    {
        return [
            'items' => [['order_item_id' => $order->items()->value('id'), 'quantity' => $quantity]],
            'resolution' => $resolution, 'reason' => 'defective', 'description' => 'Leaks', 'idempotency_key' => 'ret-'.uniqid(),
        ];
    }

    /** Opens a return and takes it to "inspected". */
    protected function inspected(Order $order, int $quantity, int $resalable, int $damaged, int $rejected, string $resolution = 'refund'): string
    {
        $id = $this->actingAs($this->owner)->postJson("/api/v1/orders/{$order->public_id}/returns", $this->body($order, $quantity, $resolution))->assertCreated()->json('data.id');
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/approve", ['return_method' => 'drop_off', 'return_shipping_paid_by' => 'store'])->assertOk();
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/receive")->assertOk()->assertJsonPath('data.status', 'received');
        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/inspect", ['items' => [[
            'order_item_id' => $order->items()->value('id'), 'resalable' => $resalable, 'damaged' => $damaged, 'rejected' => $rejected, 'note' => 'Checked',
        ]]])->assertOk();

        return $id;
    }

    protected function storeSetting(string $key, mixed $value): void
    {
        DB::table('store_settings')->updateOrInsert(['store_id' => $this->store->id, 'key' => $key], ['value' => json_encode([$value]), 'created_at' => now(), 'updated_at' => now()]);
        \Illuminate\Support\Facades\Cache::forget("settings:store:{$this->store->id}:{$key}");
    }

    /**
     * Back to the staff session after a request made with a customer token:
     * the customer guard had made itself the default one, so a plain
     * actingAs() would sign the staff member in as a "customer".
     */
    protected function asStaff(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
    }

    /** @return array<string, string> bearer-token headers of the customer API, on this store */
    protected function as(Customer $customer): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$customer->createToken('t')->plainTextToken, 'X-Store-Slug' => $this->store->slug, 'Accept' => 'application/json'];
    }
}
