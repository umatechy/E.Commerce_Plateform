<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\ReservationStatus;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B4 — Stock Reservation lifecycle (Module 08 §20-22) +
 * Concurrency (§23, this milestone's dedicated "Concurrency" section).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class ReservationTest extends TestCase
{
    use RefreshDatabase;

    private function serviceFor(Store $store): InventoryService
    {
        $this->app->make(TenantContext::class)->resolveToStore($store->id);

        return $this->app->make(InventoryService::class);
    }

    public function test_reservation_increases_reserved_and_reduces_available(): void
    {
        $store = Store::factory()->create();
        $warehouse = Warehouse::factory()->for($store)->create();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['on_hand' => 100, 'reserved' => 0]);
        $service = $this->serviceFor($store);

        $service->reserve($inventory, 30, idempotencyKey: 'res-1');

        $fresh = $inventory->fresh();
        $this->assertSame(30, $fresh->reserved);
        $this->assertSame(70, $fresh->available());
    }

    public function test_reservation_fails_when_insufficient_available_stock(): void
    {
        $store = Store::factory()->create();
        $warehouse = Warehouse::factory()->for($store)->create();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['on_hand' => 10, 'reserved' => 5]);
        $service = $this->serviceFor($store);

        $this->expectException(InsufficientStockException::class);
        $service->reserve($inventory, 10, idempotencyKey: 'res-2'); // only 5 available
    }

    public function test_reservation_allowed_beyond_available_when_store_allows_overselling(): void
    {
        $store = Store::factory()->create(['allow_overselling' => true]);
        $warehouse = Warehouse::factory()->for($store)->create();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['on_hand' => 5, 'reserved' => 0]);
        $service = $this->serviceFor($store);

        $reservation = $service->reserve($inventory, 20, idempotencyKey: 'res-3');

        $this->assertSame(ReservationStatus::Active, $reservation->status);
        $this->assertSame(20, $inventory->fresh()->reserved);
    }

    public function test_duplicate_reservation_request_returns_the_same_reservation(): void
    {
        $store = Store::factory()->create();
        $warehouse = Warehouse::factory()->for($store)->create();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['on_hand' => 100, 'reserved' => 0]);
        $service = $this->serviceFor($store);

        $first = $service->reserve($inventory, 10, idempotencyKey: 'dup-key');
        $second = $service->reserve($inventory->fresh(), 10, idempotencyKey: 'dup-key');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(10, $inventory->fresh()->reserved); // NOT 20
    }

    public function test_releasing_a_reservation_frees_reserved_quantity(): void
    {
        $store = Store::factory()->create();
        $warehouse = Warehouse::factory()->for($store)->create();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['on_hand' => 100, 'reserved' => 0]);
        $service = $this->serviceFor($store);

        $reservation = $service->reserve($inventory, 30, idempotencyKey: 'res-release');
        $service->release($reservation);

        $this->assertSame(0, $inventory->fresh()->reserved);
        $this->assertSame(ReservationStatus::Released, $reservation->fresh()->status);
    }

    public function test_releasing_an_already_released_reservation_is_a_safe_no_op(): void
    {
        $store = Store::factory()->create();
        $warehouse = Warehouse::factory()->for($store)->create();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['on_hand' => 100, 'reserved' => 0]);
        $service = $this->serviceFor($store);

        $reservation = $service->reserve($inventory, 30, idempotencyKey: 'res-double-release');
        $service->release($reservation);
        $service->release($reservation->fresh()); // second call — must not double-free

        $this->assertSame(0, $inventory->fresh()->reserved); // NOT negative
    }

    public function test_expired_reservations_are_released_by_the_expiry_job(): void
    {
        $store = Store::factory()->create();
        $warehouse = Warehouse::factory()->for($store)->create();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['on_hand' => 100, 'reserved' => 0]);
        $service = $this->serviceFor($store);

        $reservation = $service->reserve($inventory, 30, idempotencyKey: 'res-expiry', ttlMinutes: 1);
        \Illuminate\Support\Facades\DB::table('stock_reservations')
            ->where('id', $reservation->id)
            ->update(['expires_at' => now()->subMinute()]);

        $expiredCount = $service->expireStaleReservations();

        $this->assertSame(1, $expiredCount);
        $this->assertSame(0, $inventory->fresh()->reserved);
        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
    }

    /**
     * Concurrency: this milestone's exact "stock=1, two buyers" scenario.
     * Simulated sequentially (no real concurrent process in this
     * environment — see class docblock in InventoryService for the
     * atomic-UPDATE strategy that makes this safe under REAL
     * concurrency; a genuine parallel-request test is deferred to VS
     * Code with a real MySQL instance).
     */
    public function test_two_reservations_cannot_both_succeed_when_only_one_unit_is_available(): void
    {
        $store = Store::factory()->create();
        $warehouse = Warehouse::factory()->for($store)->create();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['on_hand' => 1, 'reserved' => 0]);
        $service = $this->serviceFor($store);

        $service->reserve($inventory, 1, idempotencyKey: 'buyer-a');

        $this->expectException(InsufficientStockException::class);
        $service->reserve($inventory->fresh(), 1, idempotencyKey: 'buyer-b');
    }

    public function test_reservation_requires_authentication_and_authorization(): void
    {
        $store = Store::factory()->create();
        $warehouse = Warehouse::factory()->for($store)->create();
        $inventory = Inventory::factory()->for($store)->for($warehouse)->create(['on_hand' => 10]);

        $this->postJson("/api/v1/inventory/{$inventory->id}/reservations", [
            'quantity' => 1, 'idempotency_key' => 'anon-key',
        ])->assertStatus(401);
    }
}
