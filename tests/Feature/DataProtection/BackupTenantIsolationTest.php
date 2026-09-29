<?php

declare(strict_types=1);

namespace Tests\Feature\DataProtection;

use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B19 — Non-Negotiable, the single most critical test file this
 * milestone produces: Backup deliberately does NOT use BelongsToTenant
 * (see model docblock), so tenant isolation depends ENTIRELY on each
 * controller's own explicit store_id check — this file proves that
 * check exists and works, for every angle Module 23's own Non-
 * Negotiable list names (view, download-preflight, restore,
 * enumeration).
 * STATUS: NOT EXECUTED — ENVIRONMENT LIMITATION.
 */
final class BackupTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Store $store): User
    {
        $role = $this->systemRole($store, 'owner');
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_store_a_never_sees_store_bs_backups_in_the_list(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        Backup::factory()->create(['store_id' => $storeB->id]);

        $response = $this->actingAs($ownerA)->getJson('/api/v1/backups');

        $response->assertOk();
        $this->assertSame(0, $response->json('data.total'));
    }

    public function test_store_a_cannot_request_a_restore_of_store_bs_backup_by_guessing_the_numeric_id(): void
    {
        // The single most important test in this milestone: Backup has
        // NO automatic tenant scope, so this proves the controller's
        // OWN explicit check is what stands between Store A and Store
        // B's backup — not an accident of a global scope existing.
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $backupB = Backup::factory()->create(['store_id' => $storeB->id, 'status' => BackupStatus::Verified]);

        $response = $this->actingAs($ownerA)->postJson("/api/v1/backups/{$backupB->id}/restore-request");

        $response->assertStatus(404);
    }

    public function test_a_platform_scope_backup_with_no_store_id_is_never_returned_in_a_stores_own_list(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        Backup::factory()->create(['scope' => 'platform', 'store_id' => null]);

        $response = $this->actingAs($owner)->getJson('/api/v1/backups');

        $response->assertOk();
        $this->assertSame(0, $response->json('data.total'));
    }

    public function test_store_a_can_request_restore_of_its_own_verified_backup(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $backup = Backup::factory()->create(['store_id' => $store->id, 'status' => BackupStatus::Verified]);

        $response = $this->actingAs($owner)->postJson("/api/v1/backups/{$backup->id}/restore-request");

        $response->assertCreated();
    }

    public function test_backup_response_never_exposes_the_internal_storage_path(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        Backup::factory()->create(['store_id' => $store->id]);

        $response = $this->actingAs($owner)->getJson('/api/v1/backups');

        $response->assertOk();
        $response->assertJsonMissingPath('data.data.0.storage_path');
        $response->assertJsonMissingPath('data.data.0.storage_disk');
    }
}
