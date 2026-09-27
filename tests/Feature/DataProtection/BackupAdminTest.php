<?php

declare(strict_types=1);

namespace Tests\Feature\DataProtection;

use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase B19 — Module 23 Phase 17 Non-Negotiable: "a user who can view
 * backups must not automatically be allowed to restore them." This
 * file proves backups.view/manage/restore are three genuinely separate
 * permissions, not one bundled capability.
 * STATUS: NOT EXECUTED — ENVIRONMENT LIMITATION.
 */
final class BackupAdminTest extends TestCase
{
    use RefreshDatabase;

    private function staffWithPermissions(Store $store, array $permissionKeys): User
    {
        $role = Role::factory()->for($store)->create(['slug' => 'custom-'.uniqid()]);
        foreach ($permissionKeys as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['group' => 'backups', 'description' => 'x']);
            DB::table('permission_role')->insert(['role_id' => $role->id, 'permission_id' => $permission->id]);
        }
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_a_view_only_staff_member_can_list_backups(): void
    {
        $store = Store::factory()->create();
        $staff = $this->staffWithPermissions($store, ['backups.view']);

        $response = $this->actingAs($staff)->getJson('/api/v1/backups');

        $response->assertOk();
    }

    public function test_a_view_only_staff_member_cannot_request_a_new_backup(): void
    {
        Queue::fake();
        $store = Store::factory()->create();
        $staff = $this->staffWithPermissions($store, ['backups.view']);

        $response = $this->actingAs($staff)->postJson('/api/v1/backups');

        $response->assertStatus(403);
    }

    public function test_a_staff_member_with_manage_but_not_restore_cannot_request_a_restore(): void
    {
        // Non-Negotiable: view/manage NEVER implies restore.
        $store = Store::factory()->create();
        $staff = $this->staffWithPermissions($store, ['backups.view', 'backups.manage']);
        $backup = Backup::factory()->create(['store_id' => $store->id, 'status' => BackupStatus::Verified]);

        $response = $this->actingAs($staff)->postJson("/api/v1/backups/{$backup->id}/restore-request");

        $response->assertStatus(403);
    }

    public function test_a_staff_member_with_restore_permission_can_request_a_restore(): void
    {
        $store = Store::factory()->create();
        $staff = $this->staffWithPermissions($store, ['backups.view', 'backups.restore']);
        $backup = Backup::factory()->create(['store_id' => $store->id, 'status' => BackupStatus::Verified]);

        $response = $this->actingAs($staff)->postJson("/api/v1/backups/{$backup->id}/restore-request");

        $response->assertCreated();
    }
}
