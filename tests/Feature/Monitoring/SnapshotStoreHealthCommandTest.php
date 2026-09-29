<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Domain\Monitoring\Models\StoreHealthSnapshot;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase B21 — the hourly store-health:snapshot command. */
final class SnapshotStoreHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_live_store_gets_a_snapshot_and_closed_stores_are_skipped(): void
    {
        $active = Store::factory()->create(['status' => 'active']);
        $settingUp = Store::factory()->create(['status' => 'pending_setup']);
        $cancelled = Store::factory()->create(['status' => 'cancelled']);

        $this->artisan('store-health:snapshot')->assertSuccessful();

        $snapshots = StoreHealthSnapshot::query()->withoutTenantScope()->get()->keyBy('store_id');
        $this->assertTrue($snapshots->has($active->id));
        $this->assertTrue($snapshots->has($settingUp->id));
        $this->assertFalse($snapshots->has($cancelled->id));
        // No subscription in these fixtures, so each is critical.
        $this->assertSame('critical', $snapshots[$active->id]->overall_status->value);
        $this->assertNotEmpty($snapshots[$active->id]->checks);
    }

    public function test_snapshots_past_their_retention_are_pruned(): void
    {
        config(['monitoring.store_health.snapshot_retention_days' => 30]);
        $store = Store::factory()->create(['status' => 'cancelled']); // skipped, so only the seeded rows exist
        $old = StoreHealthSnapshot::query()->create(['store_id' => $store->id, 'overall_status' => 'ok', 'checks' => []]);
        StoreHealthSnapshot::query()->withoutTenantScope()->whereKey($old->id)->update(['created_at' => now()->subDays(31)]);
        $recent = StoreHealthSnapshot::query()->create(['store_id' => $store->id, 'overall_status' => 'ok', 'checks' => []]);

        $this->artisan('store-health:snapshot')->assertSuccessful();

        $this->assertNull(StoreHealthSnapshot::query()->withoutTenantScope()->find($old->id));
        $this->assertNotNull(StoreHealthSnapshot::query()->withoutTenantScope()->find($recent->id));
    }
}
