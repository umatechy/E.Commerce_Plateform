<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use App\Domain\Compliance\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase G1 — Module 02 §19, §21: changing, suspending and removing team members. */
final class TeamMembershipTest extends TestCase
{
    use InteractsWithTeam, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTeam();
    }

    public function test_every_store_gets_the_seven_predefined_roles(): void
    {
        $store = $this->teamStore();
        $owner = $this->memberAs($store, 'owner');

        $slugs = collect($this->actingAs($owner)->getJson('/api/v1/team/summary')->assertOk()->json('data.roles'))->pluck('slug')->sort()->values()->all();
        $this->assertSame(['administrator', 'content-marketing', 'inventory-manager', 'manager', 'order-manager', 'owner', 'staff'], $slugs);
    }

    public function test_suspending_cuts_access_and_reactivating_restores_it(): void
    {
        $store = $this->teamStore();
        $owner = $this->memberAs($store, 'owner');
        $clerk = $this->memberAs($store, 'order-manager');

        $this->actingAs($clerk)->getJson('/api/v1/orders')->assertOk();

        $this->actingAs($owner)->postJson("/api/v1/team/members/{$clerk->public_id}/suspend")->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->app['auth']->forgetGuards();
        $this->actingAs($clerk)->getJson('/api/v1/orders')->assertForbidden();

        $this->actingAs($owner)->postJson("/api/v1/team/members/{$clerk->public_id}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->app['auth']->forgetGuards();
        $this->actingAs($clerk)->getJson('/api/v1/orders')->assertOk();

        $actions = AuditLog::query()->withoutGlobalScopes()->where('store_id', $store->id)->pluck('action')->all();
        $this->assertContains('team.member_suspended', $actions);
        $this->assertContains('team.member_reactivated', $actions);
    }

    public function test_the_owner_and_ones_own_membership_are_protected(): void
    {
        $store = $this->teamStore();
        $owner = $this->memberAs($store, 'owner');
        $admin = $this->memberAs($store, 'administrator');

        $this->actingAs($admin)->postJson("/api/v1/team/members/{$owner->public_id}/suspend")
            ->assertStatus(422)->assertJsonValidationErrors(['form' => 'store owner cannot be changed']);
        $this->actingAs($admin)->deleteJson("/api/v1/team/members/{$owner->public_id}")->assertStatus(422);
        $this->actingAs($admin)->patchJson("/api/v1/team/members/{$admin->public_id}", ['role' => 'manager'])
            ->assertStatus(422)->assertJsonValidationErrors(['form' => 'your own membership']);
        $this->actingAs($owner)->patchJson("/api/v1/team/members/{$owner->public_id}", ['role' => 'staff'])->assertStatus(422);
        $this->actingAs($owner)->patchJson("/api/v1/team/members/{$admin->public_id}", ['role' => 'owner'])
            ->assertStatus(422)->assertJsonValidationErrors(['role']);
    }

    public function test_a_member_with_more_authority_cannot_be_changed_by_a_lesser_one(): void
    {
        $store = $this->teamStore();
        $this->memberAs($store, 'owner');
        $admin = $this->memberAs($store, 'administrator');
        $manager = $this->memberAs($store, 'manager');
        $staff = $this->memberAs($store, 'staff');

        // A Manager has no users.manage at all.
        $this->actingAs($manager)->postJson("/api/v1/team/members/{$staff->public_id}/suspend")->assertForbidden();

        // An Administrator can manage a Manager, but not promote them beyond their own permissions.
        $this->actingAs($admin)->patchJson("/api/v1/team/members/{$manager->public_id}", ['role' => 'order-manager'])->assertOk()->assertJsonPath('data.role.slug', 'order-manager');
        $this->actingAs($admin)->patchJson("/api/v1/team/members/{$staff->public_id}", ['role' => 'administrator'])->assertOk(); // equal authority is allowed

        $members = collect($this->actingAs($admin)->getJson('/api/v1/team/members')->assertOk()->json('data'))->keyBy('id');
        $this->assertFalse($members[$admin->public_id]['can_manage']); // self
        $this->assertTrue($members[$manager->public_id]['can_manage']);
    }

    public function test_removal_keeps_history_and_the_person_can_be_invited_back(): void
    {
        $store = $this->teamStore();
        $owner = $this->memberAs($store, 'owner');
        $leaver = $this->memberAs($store, 'staff', ['email' => 'leaver@example.com']);

        $this->actingAs($owner)->deleteJson("/api/v1/team/members/{$leaver->public_id}")->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->assertDatabaseHas('store_user', ['store_id' => $store->id, 'user_id' => $leaver->id, 'status' => 'revoked', 'status_changed_by_user_id' => $owner->id]);
        $this->actingAs($owner)->deleteJson("/api/v1/team/members/{$leaver->public_id}")->assertStatus(422);

        [$id, $token] = $this->invite($owner, 'leaver@example.com', 'inventory-manager');
        $this->app['auth']->forgetGuards();
        $this->inBrowserAs($leaver)->postJson("/api/v1/public/invitations/{$id}/accept", ['token' => $token])->assertOk();
        $this->assertDatabaseHas('store_user', ['store_id' => $store->id, 'user_id' => $leaver->id, 'status' => 'active', 'role_id' => $this->systemRole($store, 'inventory-manager')->id]);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('store_user')->where('user_id', $leaver->id)->count());
    }

    public function test_another_store_cannot_see_or_change_the_members(): void
    {
        $storeA = $this->teamStore();
        $this->memberAs($storeA, 'owner');
        $memberA = $this->memberAs($storeA, 'staff');

        $storeB = $this->teamStore();
        $ownerB = $this->memberAs($storeB, 'owner');

        $this->actingAs($ownerB)->getJson('/api/v1/team/members')->assertOk()->assertJsonMissing(['id' => $memberA->public_id]);
        $this->actingAs($ownerB)->postJson("/api/v1/team/members/{$memberA->public_id}/suspend")->assertNotFound();
        $this->actingAs($ownerB)->patchJson("/api/v1/team/members/{$memberA->public_id}", ['role' => 'staff'])->assertNotFound();
        $this->assertDatabaseHas('store_user', ['user_id' => $memberA->id, 'status' => 'active']);
    }
}
