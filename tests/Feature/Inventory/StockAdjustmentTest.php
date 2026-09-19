<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Exceptions\DuplicateOpeningStockException;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\StockMovementType;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B4 — Opening Stock (Module 08 §32) + Stock Adjustment (§33) +
 * Negative Stock prevention (§24) + Idempotency (§59) +
 * Immutable Ledger (this milestone's dedicated section).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Store $store): User
    {
        $role = Role::factory()->for($store)->create(['slug' => 'owner']);
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    private function serviceFor(Store $store): InventoryService
    {
        $this->app->make(TenantContext::class)->resolveToStore($store->id);

        return $this->app->make(InventoryService::class);
    }

    public function test_opening_stock_sets_on_hand_and_creates_a_movement(): void
    {
        $store = Store::factory()->create();
        $warehouse = Warehouse::factory()->for($store)->create();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['on_hand' => 0]);
        $service = $this->serviceFor($store);

        $movement = $service->setOpeningStock($inventory, 100, 'Initial stock count', actorId: 1, idempotencyKey: 'open-1');

        $this->assertSame(StockMovementType::OpeningBalance, $movement->type);
        $this->assertSame(100, $inventory->fresh()->on_hand);
    }

    public function test_opening_stock_cannot_be_set_twice(): void
    {
        $store = Store::factory()->create();
        $warehouse = Warehouse::factory()->for($store)->create();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['on_hand' => 0]);
        $service = $this->serviceFor($store);

        $service->setOpeningStock($inventory, 100, 'First', actorId: 1, idempotencyKey: 'open-1');

        $this->expectException(DuplicateOpeningStockException::class);
        $service->setOpeningStock($inventory->fresh(), 50, 'Second attempt', actorId: 1, idempotencyKey: 'open-2');
    }

    public function test_positive_adjustment_increases_on_hand(): void
    {
        $store = Store::factory()->create();
        $warehouse = Warehouse::factory()->for($store)->create();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['on_hand' => 50]);
        $service = $this->serviceFor($store);

        $movement = $service->adjustStock($inventory, 10, 'Found stock', actorId: 1, idempotencyKey: 'adj-1');

        $this->assertSame(StockMovementType::AdjustmentIn, $movement->type);
        $this->assertSame(60, $inventory->fresh()->on_hand);
    }

    public function test_negative_adjustment_decreases_on_hand(): void
    {
        $store = Store::factory()->create();
        $warehouse = Warehouse::factory()->for($store)->create();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['on_hand' => 50]);
        $service = $this->serviceFor($store);

        $movement = $service->adjustStock($inventory, -10, 'Damaged goods', actorId: 1, idempotencyKey: 'adj-2');

        $this->assertSame(StockMovementType::AdjustmentOut, $movement->type);
        $this->assertSame(40, $inventory->fresh()->on_hand);
    }

    public function test_adjustment_cannot_take_stock_negative(): void
    {
        $store = Store::factory()->create();
        $warehouse = Warehouse::factory()->for($store)->create();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['on_hand' => 5]);
        $service = $this->serviceFor($store);

        $this->expectException(InsufficientStockException::class);
        $service->adjustStock($inventory, -10, 'Too much', actorId: 1, idempotencyKey: 'adj-3');
    }

    public function test_adjustment_cannot_go_negative_even_when_store_allows_overselling(): void
    {
        // Documented decision (docs/architecture/b4-inventory.md): overselling
        // is a SALES policy, not an adjustment/data-correction policy.
        $store = Store::factory()->create(['allow_overselling' => true]);
        $warehouse = Warehouse::factory()->for($store)->create();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['on_hand' => 5]);
        $service = $this->serviceFor($store);

        $this->expectException(InsufficientStockException::class);
        $service->adjustStock($inventory, -10, 'Too much', actorId: 1, idempotencyKey: 'adj-4');
    }

    public function test_duplicate_adjustment_request_is_idempotent(): void
    {
        $store = Store::factory()->create();
        $warehouse = Warehouse::factory()->for($store)->create();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['on_hand' => 50]);
        $service = $this->serviceFor($store);

        $service->adjustStock($inventory, 10, 'Found stock', actorId: 1, idempotencyKey: 'same-key');
        $service->adjustStock($inventory->fresh(), 10, 'Found stock', actorId: 1, idempotencyKey: 'same-key');

        $this->assertSame(60, $inventory->fresh()->on_hand); // NOT 70 — the second call was a no-op replay
        $this->assertSame(1, \App\Domain\Inventory\Models\StockMovement::query()->where('idempotency_key', 'same-key')->count());
    }

    public function test_adjustment_creates_an_auditable_movement_with_reason_and_actor(): void
    {
        $store = Store::factory()->create();
        $warehouse = Warehouse::factory()->for($store)->create();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['on_hand' => 50]);
        $owner = $this->ownerOf($store);
        $service = $this->serviceFor($store);

        $movement = $service->adjustStock($inventory, 5, 'Physical count correction', actorId: $owner->id, idempotencyKey: 'adj-audit');

        $this->assertSame('Physical count correction', $movement->reason);
        $this->assertSame($owner->id, $movement->actor_id);
        $this->assertSame(50, $movement->previous_on_hand);
        $this->assertSame(55, $movement->new_on_hand);
    }

    public function test_api_adjustment_endpoint_enforces_authorization(): void
    {
        $store = Store::factory()->create();
        $warehouse = Warehouse::factory()->for($store)->create();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['on_hand' => 50]);
        $role = Role::factory()->for($store)->create(['slug' => 'staff']);
        $staff = User::factory()->create();
        $store->users()->attach($staff, ['role_id' => $role->id, 'status' => 'active']);

        $response = $this->actingAs($staff)->postJson("/api/v1/inventory/{$inventory->id}/adjust", [
            'quantity' => 10, 'reason' => 'Test',
        ]);

        $response->assertStatus(403);
    }
}
