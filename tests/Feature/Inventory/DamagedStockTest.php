<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B34 — Module 08 §47 "Damaged Stock": a quantity of its own,
 * apart from what can be sold, with an auditable way in and out.
 */
final class DamagedStockTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Store, 1: User, 2: Inventory} with 10 on hand, 3 of them reserved */
    private function stock(): array
    {
        $store = Store::factory()->create();
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);
        app(TenantContext::class)->resolveToStore($store->id);
        $inventory = Inventory::factory()->for($store)->for(Warehouse::factory()->for($store)->create())->create(['on_hand' => 0]);
        $service = app(InventoryService::class);
        $service->setOpeningStock($inventory, 10, 'Init', actorId: $owner->id, idempotencyKey: 'open');
        $service->reserve($inventory->fresh(), 3, idempotencyKey: 'res', referenceType: 'order', referenceId: 1);

        return [$store, $owner, $inventory->fresh()];
    }

    public function test_marking_damaged_takes_only_available_units_and_counts_them_apart(): void
    {
        [, $owner, $inventory] = $this->stock();
        $url = "/api/v1/inventory/{$inventory->public_id}/damaged";

        // 7 are available; the 3 reserved are promised to an order.
        $this->actingAs($owner)->postJson($url, ['quantity' => 8, 'reason' => 'Flood'])->assertUnprocessable()->assertJsonPath('code', 'insufficient_stock');
        $this->actingAs($owner)->postJson($url, ['quantity' => 0, 'reason' => 'x'])->assertUnprocessable();
        $this->actingAs($owner)->postJson($url, ['quantity' => 2])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->actingAs($owner)->postJson($url, ['quantity' => 2, 'reason' => 'Broken seal', 'idempotency_key' => 'dmg-1'])->assertCreated()->assertJsonPath('data.type', 'damage_out');
        // The same request again records nothing more.
        $this->actingAs($owner)->postJson($url, ['quantity' => 2, 'reason' => 'Broken seal', 'idempotency_key' => 'dmg-1'])->assertCreated();

        $inventory->refresh();
        $this->assertSame([8, 3, 2], [$inventory->on_hand, $inventory->reserved, $inventory->damaged]);
        $this->assertSame(5, $inventory->available(), 'damaged units are not available');
        $this->actingAs($owner)->getJson("/api/v1/inventory/{$inventory->public_id}")->assertOk()->assertJsonPath('data.damaged', 2)->assertJsonPath('data.available', 5);
    }

    public function test_writing_off_lowers_only_the_damaged_balance(): void
    {
        [, $owner, $inventory] = $this->stock();
        $this->actingAs($owner)->postJson("/api/v1/inventory/{$inventory->public_id}/damaged", ['quantity' => 3, 'reason' => 'Dropped'])->assertCreated();
        $url = "/api/v1/inventory/{$inventory->public_id}/damaged/write-off";

        $this->actingAs($owner)->postJson($url, ['quantity' => 4, 'reason' => 'Bin'])->assertUnprocessable()->assertJsonPath('code', 'insufficient_stock');
        $this->actingAs($owner)->postJson($url, ['quantity' => 2, 'reason' => 'Thrown away'])->assertCreated()->assertJsonPath('data.type', 'damaged_write_off');

        $inventory->refresh();
        $this->assertSame([7, 1], [$inventory->on_hand, $inventory->damaged]);
        $movement = StockMovement::query()->where('type', 'damaged_write_off')->sole();
        $this->assertSame([-2, 7, 7, 'Thrown away'], [$movement->quantity, $movement->previous_on_hand, $movement->new_on_hand, $movement->reason]);
    }

    public function test_only_who_may_adjust_stock_and_only_in_their_store(): void
    {
        [$store, , $inventory] = $this->stock();
        $viewer = User::factory()->create();
        $store->users()->attach($viewer, ['role_id' => $this->systemRole($store, 'staff')->id, 'status' => 'active']);
        $outsider = User::factory()->create();
        $other = Store::factory()->create();
        $other->users()->attach($outsider, ['role_id' => $this->systemRole($other, 'owner')->id, 'status' => 'active']);

        $this->actingAs($viewer)->postJson("/api/v1/inventory/{$inventory->public_id}/damaged", ['quantity' => 1, 'reason' => 'x'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs($outsider)->postJson("/api/v1/inventory/{$inventory->public_id}/damaged", ['quantity' => 1, 'reason' => 'x'])->assertNotFound();
        $this->assertSame(0, $inventory->fresh()->damaged);
    }
}
