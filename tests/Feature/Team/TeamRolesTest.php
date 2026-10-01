<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Identity\Models\Role;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Phase G1 — custom roles (Module 02 §17, Module 04 §7) and the predefined-role backfill. */
final class TeamRolesTest extends TestCase
{
    use InteractsWithTeam, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTeam();
    }

    public function test_custom_roles_need_the_package_feature_but_existing_ones_stay_deletable(): void
    {
        $store = $this->teamStore(); // package without custom_roles.enabled
        $owner = $this->memberAs($store, 'owner');
        $existing = Role::factory()->for($store)->create(['name' => 'Packer', 'slug' => 'packer', 'is_system' => false]);

        $this->actingAs($owner)->postJson('/api/v1/roles', ['name' => 'Warehouse'])->assertForbidden()->assertJsonPath('code', 'feature_not_entitled');
        $this->actingAs($owner)->putJson("/api/v1/roles/{$existing->id}", ['name' => 'Packer 2'])->assertForbidden();
        // A downgraded store keeps control over what it already has.
        $this->actingAs($owner)->deleteJson("/api/v1/roles/{$existing->id}")->assertNoContent();
    }

    public function test_role_names_are_unique_and_changes_are_audited(): void
    {
        $store = $this->teamStore(features: ['custom_roles.enabled']);
        $owner = $this->memberAs($store, 'owner');

        $this->actingAs($owner)->postJson('/api/v1/roles', ['name' => 'Packer', 'permission_keys' => ['orders.view']])->assertCreated();
        $this->actingAs($owner)->postJson('/api/v1/roles', ['name' => 'packer'])->assertStatus(422)->assertJsonValidationErrors(['name' => 'already has a role']);
        $this->actingAs($owner)->postJson('/api/v1/roles', ['name' => 'Order Manager'])->assertStatus(422); // clashes with a predefined role

        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('store_id', $store->id)->where('action', 'role.created')->exists());
    }

    public function test_a_role_someone_holds_cannot_be_deleted(): void
    {
        $store = $this->teamStore();
        $owner = $this->memberAs($store, 'owner');
        $role = Role::factory()->for($store)->create(['name' => 'Packer', 'slug' => 'packer', 'is_system' => false]);
        $packer = $this->memberAs($store, 'staff');
        $store->users()->updateExistingPivot($packer->id, ['role_id' => $role->id]);

        $this->actingAs($owner)->deleteJson("/api/v1/roles/{$role->id}")->assertStatus(422)->assertJsonValidationErrors(['role']);
        $this->assertDatabaseHas('store_user', ['user_id' => $packer->id, 'role_id' => $role->id]);

        // Once they have left, it can go (their history row keeps no role).
        $this->actingAs($owner)->deleteJson("/api/v1/team/members/{$packer->public_id}")->assertOk();
        $this->actingAs($owner)->deleteJson("/api/v1/roles/{$role->id}")->assertNoContent();
        $this->assertDatabaseHas('store_user', ['user_id' => $packer->id, 'role_id' => null, 'status' => 'revoked']);
    }

    public function test_existing_stores_get_the_four_new_predefined_roles(): void
    {
        $store = Store::factory()->create();
        $old = Store::factory()->create();
        // As before G1: only owner, manager and staff; and one custom role that happens to use a new slug.
        DB::table('roles')->where('store_id', $old->id)->whereIn('slug', ['administrator', 'order-manager', 'inventory-manager', 'content-marketing'])->delete();
        $clash = Role::factory()->for($old)->create(['name' => 'Our order desk', 'slug' => 'order-manager', 'is_system' => false]);

        $migration = require database_path('migrations/2028_02_01_000004_add_team_roles_to_existing_stores.php');
        $migration->up();
        $migration->up(); // safe to run twice

        $slugs = fn (Store $s) => Role::query()->withoutTenantScope()->where('store_id', $s->id)->where('is_system', true)->orderBy('slug')->pluck('slug')->all();
        $this->assertSame(['administrator', 'content-marketing', 'inventory-manager', 'manager', 'owner', 'staff'], $slugs($old));
        $this->assertSame('Our order desk', $clash->fresh()->name); // the custom role is left alone
        $this->assertCount(7, $slugs($store));

        $admin = Role::query()->withoutTenantScope()->where('store_id', $old->id)->where('slug', 'administrator')->sole();
        $this->assertContains('users.manage', $admin->permissions()->pluck('key')->all());
        $this->assertNotContains('billing.manage', $admin->permissions()->pluck('key')->all());
    }
}
