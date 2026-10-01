<?php

declare(strict_types=1);

namespace Tests\Feature\Team;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Identity\Models\StoreInvitation;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\NotificationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Phase G1 — Module 02 §18: inviting people to a store's team. */
final class TeamInvitationTest extends TestCase
{
    use InteractsWithTeam, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTeam();
    }

    public function test_a_new_person_accepts_an_invitation_and_joins_with_the_invited_role(): void
    {
        $store = $this->teamStore();
        $owner = $this->memberAs($store, 'owner');
        [$id, $token] = $this->invite($owner, 'Sara@Example.com', 'order-manager');

        // Only a hash is stored; the admin-readable email body hides the link.
        $invitation = StoreInvitation::query()->sole();
        $this->assertSame('sara@example.com', $invitation->email);
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $mail = NotificationMessage::query()->where('destination', 'sara@example.com')->sole();
        $this->assertStringNotContainsString($token, $mail->body);
        $this->assertStringContainsString("/invitations/{$id}#token={$token}", $mail->sealed_body);

        $this->signOut();
        $this->postJson("/api/v1/public/invitations/{$id}/lookup", ['token' => $token])->assertOk()
            ->assertJsonPath('data.store', $store->name)->assertJsonPath('data.role', 'Order Manager')
            ->assertJsonPath('data.email', 'sara@example.com')->assertJsonPath('data.has_account', false);

        $this->postJson("/api/v1/public/invitations/{$id}/accept", [
            'token' => $token, 'name' => 'Sara Khan', 'password' => 'correct-horse-battery-9', 'password_confirmation' => 'correct-horse-battery-9',
        ])->assertOk()->assertJsonPath('data.store', $store->name);

        $sara = User::query()->where('email', 'sara@example.com')->sole();
        $this->assertNotNull($sara->email_verified_at); // the emailed link proved the address
        $this->assertDatabaseHas('store_user', ['store_id' => $store->id, 'user_id' => $sara->id, 'role_id' => $this->systemRole($store, 'order-manager')->id, 'status' => 'active']);
        $this->assertSame('accepted', $invitation->fresh()->status->value);
        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('action', 'team.invitation_accepted')->where('store_id', $store->id)->exists());

        // Single use: the same link never works twice.
        $this->signOut();
        $this->postJson("/api/v1/public/invitations/{$id}/lookup", ['token' => $token])->assertNotFound();
    }

    public function test_an_existing_account_must_be_signed_in_as_the_invited_address(): void
    {
        $store = $this->teamStore();
        $owner = $this->memberAs($store, 'owner');
        $existing = User::factory()->create(['email' => 'ali@example.com']);
        $someoneElse = User::factory()->create(['email' => 'other@example.com']);
        [$id, $token] = $this->invite($owner, 'ali@example.com', 'staff');

        $this->signOut();
        $this->postJson("/api/v1/public/invitations/{$id}/accept", ['token' => $token, 'name' => 'Imposter', 'password' => 'correct-horse-battery-9', 'password_confirmation' => 'correct-horse-battery-9'])
            ->assertStatus(422)->assertJsonPath('code', 'sign_in_required');

        $this->inBrowserAs($someoneElse)->postJson("/api/v1/public/invitations/{$id}/accept", ['token' => $token])
            ->assertStatus(422)->assertJsonPath('code', 'wrong_account');

        $this->inBrowserAs($existing)->postJson("/api/v1/public/invitations/{$id}/accept", ['token' => $token])->assertOk();
        $this->assertDatabaseHas('store_user', ['store_id' => $store->id, 'user_id' => $existing->id, 'status' => 'active']);
        $this->assertNotSame('Imposter', $existing->fresh()->name); // the account was never touched
    }

    public function test_wrong_expired_and_revoked_tokens_all_look_the_same(): void
    {
        $store = $this->teamStore();
        $owner = $this->memberAs($store, 'owner');
        [$id, $token] = $this->invite($owner, 'a@example.com', 'staff');
        [$revokedId, $revokedToken] = $this->invite($owner, 'b@example.com', 'staff');
        $this->actingAs($owner)->deleteJson("/api/v1/team/invitations/{$revokedId}")->assertOk()->assertJsonPath('data.status', 'revoked');

        $this->signOut();
        $unavailable = ['code' => 'invitation_unavailable'];
        $this->postJson("/api/v1/public/invitations/{$id}/lookup", ['token' => str_repeat('x', 64)])->assertNotFound()->assertJson($unavailable);
        $this->postJson("/api/v1/public/invitations/{$revokedId}/lookup", ['token' => $revokedToken])->assertNotFound()->assertJson($unavailable);

        $this->travel(config('team.invitation_ttl_days') + 1)->days();
        $this->postJson("/api/v1/public/invitations/{$id}/accept", ['token' => $token, 'name' => 'A', 'password' => 'correct-horse-battery-9', 'password_confirmation' => 'correct-horse-battery-9'])
            ->assertNotFound()->assertJson($unavailable);
        $this->assertDatabaseMissing('users', ['email' => 'a@example.com']);
    }

    public function test_resending_replaces_the_token_and_duplicates_are_refused(): void
    {
        $store = $this->teamStore();
        $owner = $this->memberAs($store, 'owner');
        $member = $this->memberAs($store, 'staff', ['email' => 'member@example.com']);
        [$id, $oldToken] = $this->invite($owner, 'new@example.com', 'staff');

        $this->actingAs($owner)->postJson('/api/v1/team/invitations', ['email' => 'NEW@example.com', 'role' => 'staff'])
            ->assertStatus(422)->assertJsonValidationErrors(['email' => 'already has an open invitation']);
        $this->actingAs($owner)->postJson('/api/v1/team/invitations', ['email' => 'member@example.com', 'role' => 'staff'])
            ->assertStatus(422)->assertJsonValidationErrors(['email' => 'already on your team']);

        $this->actingAs($owner)->postJson("/api/v1/team/invitations/{$id}/resend")->assertOk();
        $newToken = $this->mailedToken('new@example.com');
        $this->assertNotSame($oldToken, $newToken);

        $this->signOut();
        $this->postJson("/api/v1/public/invitations/{$id}/lookup", ['token' => $oldToken])->assertNotFound();
        $this->postJson("/api/v1/public/invitations/{$id}/lookup", ['token' => $newToken])->assertOk();
    }

    public function test_nobody_can_invite_beyond_their_own_authority(): void
    {
        $store = $this->teamStore();
        $manager = $this->memberAs($store, 'manager'); // has users.invite
        $staff = $this->memberAs($store, 'staff');     // does not

        $this->actingAs($staff)->postJson('/api/v1/team/invitations', ['email' => 'x@example.com', 'role' => 'staff'])->assertForbidden();
        // Administrator holds permissions a Manager does not (users.manage, payments.refund, ...).
        $this->actingAs($manager)->postJson('/api/v1/team/invitations', ['email' => 'x@example.com', 'role' => 'administrator'])
            ->assertStatus(422)->assertJsonValidationErrors(['role' => 'permissions you have yourself']);
        $this->actingAs($manager)->postJson('/api/v1/team/invitations', ['email' => 'x@example.com', 'role' => 'owner'])
            ->assertStatus(422)->assertJsonValidationErrors(['role' => 'owner role cannot be given']);
        $this->actingAs($manager)->postJson('/api/v1/team/invitations', ['email' => 'x@example.com', 'role' => 'inventory-manager'])->assertCreated();

        // The page only offers what the caller may grant.
        $roles = collect($this->actingAs($manager)->getJson('/api/v1/team/summary')->assertOk()->json('data.roles'))->pluck('grantable', 'slug');
        $this->assertFalse($roles['owner']);
        $this->assertFalse($roles['administrator']);
        $this->assertTrue($roles['staff']);
    }

    public function test_the_package_seat_limit_counts_members_and_open_invitations_but_not_the_owner(): void
    {
        $store = $this->teamStore(seatLimit: 2);
        $owner = $this->memberAs($store, 'owner');
        $this->memberAs($store, 'staff');
        $this->invite($owner, 'second@example.com', 'staff');

        $this->actingAs($owner)->getJson('/api/v1/team/summary')->assertOk()->assertJsonPath('data.seats', ['used' => 2, 'limit' => 2]);
        $this->actingAs($owner)->postJson('/api/v1/team/invitations', ['email' => 'third@example.com', 'role' => 'staff'])
            ->assertStatus(422)->assertJsonValidationErrors(['email' => 'allows 2 team members']);
    }

    public function test_another_store_cannot_see_or_touch_the_invitations(): void
    {
        $storeA = $this->teamStore();
        $ownerA = $this->memberAs($storeA, 'owner');
        [$id] = $this->invite($ownerA, 'guest@example.com', 'staff');

        $storeB = $this->teamStore();
        $ownerB = $this->memberAs($storeB, 'owner');

        $this->actingAs($ownerB)->getJson('/api/v1/team/invitations')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($ownerB)->deleteJson("/api/v1/team/invitations/{$id}")->assertNotFound();
        $this->actingAs($ownerB)->postJson("/api/v1/team/invitations/{$id}/resend")->assertNotFound();
        $this->assertSame('pending', DB::table('store_invitations')->where('public_id', $id)->value('status'));
    }
}
