<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Returns\BuildsReturns;
use Tests\TestCase;

/**
 * Phase P4a — advanced inventory (Business, `inventory.advanced`; Module 08
 * §71): receiving from suppliers, transfers between locations (sent → on
 * its way → received or cancelled), and the reorder plan from real sales.
 */
final class StockPlanningTest extends TestCase
{
    use BuildsReturns, RefreshDatabase;

    private function include(): void
    {
        Subscription::query()->withoutGlobalScopes()->where('store_id', $this->store->id)->sole()->package
            ->entitlements()->create(['key' => 'inventory.advanced', 'type' => EntitlementType::Feature, 'boolean_value' => true]);
        \Illuminate\Support\Facades\Cache::flush();
    }

    private function stock(Warehouse $warehouse): Inventory
    {
        return Inventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $this->product->id)->firstOrFail();
    }

    public function test_receiving_adds_stock_once_with_its_document_and_needs_the_package_and_permission(): void
    {
        $main = Warehouse::query()->where('is_default', true)->firstOrFail();
        $body = ['warehouse_id' => $main->id, 'supplier' => 'Al-Haram Attars', 'reference' => 'DN-551',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 30], ['product_id' => $this->product->id, 'quantity' => 5]], 'idempotency_key' => 'rec-000001'];

        $this->actingAs($this->owner)->postJson('/api/v1/stock/receipts', $body)->assertForbidden()->assertJsonPath('code', 'feature_not_entitled');
        $this->include();
        $this->actingAs($this->owner)->postJson('/api/v1/stock/receipts', $body)->assertCreated()->assertJsonPath('data.number', 'REC-0001');
        $this->actingAs($this->owner)->postJson('/api/v1/stock/receipts', $body)->assertCreated()->assertJsonPath('data.number', 'REC-0001'); // a repeat adds nothing

        $this->assertSame(20 + 35, $this->stock($main)->on_hand);
        $movement = StockMovement::query()->where('type', 'purchase_in')->sole();
        $this->assertSame([35, 'stock_receipt'], [$movement->quantity, $movement->reference_type]);
        $this->assertSame(1, AuditLog::query()->where('action', 'inventory.stock_received')->count());
        $this->actingAs($this->owner)->getJson('/api/v1/stock/receipts')->assertOk()->assertJsonPath('data.0.supplier', 'Al-Haram Attars')->assertJsonPath('data.0.lines.0.quantity', 35);

        // Looking is inventory.view; receiving is inventory.adjust.
        $viewer = $this->staffWith(['inventory.view']);
        $this->asStaff();
        $this->actingAs($viewer)->getJson('/api/v1/stock/receipts')->assertOk();
        $this->actingAs($viewer)->postJson('/api/v1/stock/receipts', [...$body, 'idempotency_key' => 'rec-000002'])->assertForbidden();

        // Another store's location or product is refused.
        $other = Store::factory()->create();
        $foreign = Warehouse::query()->withoutTenantScope()->where('store_id', $other->id)->firstOrFail();
        $this->actingAs($this->owner)->postJson('/api/v1/stock/receipts', [...$body, 'warehouse_id' => $foreign->id, 'idempotency_key' => 'rec-000003'])->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');
    }

    public function test_a_transfer_leaves_the_source_shows_incoming_and_arrives_or_goes_back(): void
    {
        $this->include();
        $main = Warehouse::query()->where('is_default', true)->firstOrFail();
        $shop = Warehouse::factory()->for($this->store)->create(['name' => 'Saddar shop', 'code' => 'SADDAR']);
        // Two units are reserved by an order: they cannot leave.
        $this->deliveredOrder(2, deliver: false, paid: false);
        $reserved = $this->stock($main)->reserved;
        $this->assertSame(2, $reserved);

        $body = ['from_warehouse_id' => $main->id, 'to_warehouse_id' => $shop->id, 'lines' => [['product_id' => $this->product->id, 'quantity' => 19]], 'idempotency_key' => 'tr-0000001'];
        $this->actingAs($this->owner)->postJson('/api/v1/stock/transfers', $body)->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity');
        $this->actingAs($this->owner)->postJson('/api/v1/stock/transfers', [...$body, 'to_warehouse_id' => $main->id, 'idempotency_key' => 'tr-0000002'])->assertUnprocessable()->assertJsonValidationErrors('to_warehouse_id');

        $id = $this->actingAs($this->owner)->postJson('/api/v1/stock/transfers', [...$body, 'lines' => [['product_id' => $this->product->id, 'quantity' => 8]], 'idempotency_key' => 'tr-0000003'])
            ->assertCreated()->assertJsonPath('data.number', 'TR-0001')->json('data.id');
        $this->assertSame(12, $this->stock($main)->on_hand);
        $this->assertSame([0, 8], [$this->stock($shop)->on_hand, $this->stock($shop)->incoming]);

        $this->actingAs($this->owner)->postJson("/api/v1/stock/transfers/{$id}/receive")->assertOk();
        $this->actingAs($this->owner)->postJson("/api/v1/stock/transfers/{$id}/receive")->assertOk(); // again: nothing more
        $this->assertSame([8, 0], [$this->stock($shop)->on_hand, $this->stock($shop)->incoming]);
        $this->actingAs($this->owner)->postJson("/api/v1/stock/transfers/{$id}/cancel")->assertUnprocessable();

        // A second transfer called off on the way: back to the source.
        $second = $this->actingAs($this->owner)->postJson('/api/v1/stock/transfers', [...$body, 'lines' => [['product_id' => $this->product->id, 'quantity' => 4]], 'idempotency_key' => 'tr-0000004'])->json('data.id');
        $this->assertSame(8, $this->stock($main)->on_hand);
        $this->actingAs($this->owner)->postJson("/api/v1/stock/transfers/{$second}/cancel")->assertOk();
        $this->assertSame([12, 8, 0], [$this->stock($main)->on_hand, $this->stock($shop)->on_hand, $this->stock($shop)->incoming]);

        $list = $this->actingAs($this->owner)->getJson('/api/v1/stock/transfers')->assertOk()->json('data');
        $this->assertSame(['cancelled', 'received'], array_column($list, 'status'));
        $this->assertSame(4, StockMovement::query()->whereIn('type', ['transfer_out', 'transfer_in'])->count());

        // Another store cannot see or touch them.
        $outsider = User::factory()->create();
        $elsewhere = Store::factory()->create();
        $elsewhere->users()->attach($outsider, ['role_id' => $this->systemRole($elsewhere, 'owner')->id, 'status' => 'active']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($outsider)->postJson("/api/v1/stock/transfers/{$second}/receive")->assertNotFound();
    }

    public function test_the_reorder_plan_follows_real_sales(): void
    {
        $this->include();
        $this->deliveredOrder(6); // 6 sold in the last 30 days: 0.2 a day; 14 left = 70 days
        $plan = $this->actingAs($this->owner)->getJson('/api/v1/stock/reorder?sales_days=30&cover_days=90')->assertOk()->json('data');
        $row = collect($plan)->firstWhere('product_id', $this->product->id);
        $this->assertSame(['available' => 14, 'sold' => 6, 'per_day' => 0.2, 'days_left' => 70, 'suggested' => 4, 'state' => 'running_low'],
            array_intersect_key($row, array_flip(['available', 'sold', 'per_day', 'days_left', 'suggested', 'state'])));

        // Enough for 60 days: nothing to suggest, so it is not listed.
        $this->assertNull(collect($this->actingAs($this->owner)->getJson('/api/v1/stock/reorder?cover_days=60')->json('data'))->firstWhere('product_id', $this->product->id));
        $this->actingAs($this->owner)->getJson('/api/v1/stock/reorder?sales_days=3')->assertUnprocessable();
    }
}
