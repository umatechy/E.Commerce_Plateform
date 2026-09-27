<?php

declare(strict_types=1);

namespace Tests\Feature\DataProtection;

use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupRestoreJob;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase B19 — Super Admin platform-wide oversight and sole restore-
 * execution authority, reusing B16's existing platform-global group
 * unchanged (Module 23 Phase 27).
 * STATUS: NOT EXECUTED — ENVIRONMENT LIMITATION.
 */
final class SuperAdminBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_super_admin_can_see_backups_across_every_store(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        Backup::factory()->create(['store_id' => $storeA->id]);
        Backup::factory()->create(['store_id' => $storeB->id]);
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $response = $this->actingAs($superAdmin)->getJson('/api/v1/super-admin/backups');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(2, $response->json('data.total'));
    }

    public function test_non_platform_staff_cannot_reach_super_admin_backup_routes(): void
    {
        $ordinaryUser = User::factory()->create(['platform_role' => null]);

        $response = $this->actingAs($ordinaryUser)->getJson('/api/v1/super-admin/backups');

        $response->assertStatus(403);
    }

    public function test_super_admin_can_authorize_a_requested_restore(): void
    {
        Queue::fake();
        $store = Store::factory()->create();
        $backup = Backup::factory()->create(['store_id' => $store->id, 'status' => BackupStatus::Verified]);
        $restoreJob = BackupRestoreJob::query()->create(['backup_id' => $backup->id, 'target_store_id' => $store->id, 'status' => 'requested']);
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $response = $this->actingAs($superAdmin)->postJson("/api/v1/super-admin/restore-jobs/{$restoreJob->id}/authorize");

        $response->assertOk();
        $this->assertSame('running', $restoreJob->fresh()->status->value);
    }

    public function test_a_store_owner_cannot_reach_the_super_admin_restore_authorization_route(): void
    {
        $store = Store::factory()->create();
        $role = \App\Domain\Identity\Models\Role::factory()->for($store)->create(['slug' => 'owner']);
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role_id' => $role->id, 'status' => 'active']);
        $backup = Backup::factory()->create(['store_id' => $store->id, 'status' => BackupStatus::Verified]);
        $restoreJob = BackupRestoreJob::query()->create(['backup_id' => $backup->id, 'target_store_id' => $store->id, 'status' => 'requested']);

        $response = $this->actingAs($owner)->postJson("/api/v1/super-admin/restore-jobs/{$restoreJob->id}/authorize");

        $response->assertStatus(403);
    }
}
