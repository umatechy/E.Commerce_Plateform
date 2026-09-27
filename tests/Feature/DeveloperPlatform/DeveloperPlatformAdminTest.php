<?php

declare(strict_types=1);

namespace Tests\Feature\DeveloperPlatform;

use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B18 — Staff developer-platform administration, tenant
 * isolation (Module 31 §39-40/§52, Non-Negotiable).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class DeveloperPlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Store $store): User
    {
        $role = Role::factory()->for($store)->create(['slug' => 'owner']);
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_owner_can_create_an_application(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->postJson('/api/v1/developer/applications', ['name' => 'My Integration']);

        $response->assertCreated();
    }

    public function test_owner_can_issue_an_api_key_and_sees_the_secret_exactly_once(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $application = DeveloperApplication::factory()->for($store)->create();

        $response = $this->actingAs($owner)->postJson("/api/v1/developer/applications/{$application->id}/keys", ['scopes' => ['products:read']]);

        $response->assertCreated();
        $this->assertNotNull($response->json('secret'));
    }

    public function test_listing_keys_never_includes_the_secret(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $application = DeveloperApplication::factory()->for($store)->create();
        $this->actingAs($owner)->postJson("/api/v1/developer/applications/{$application->id}/keys", ['scopes' => ['products:read']]);

        $response = $this->actingAs($owner)->getJson("/api/v1/developer/applications/{$application->id}/keys");

        $response->assertOk();
        $response->assertJsonMissingPath('0.secret');
        $response->assertJsonMissingPath('0.key_hash');
    }

    public function test_manager_without_developer_platform_manage_cannot_create_applications(): void
    {
        $store = Store::factory()->create();
        $role = Role::factory()->for($store)->create(['slug' => 'manager']);
        $manager = User::factory()->create();
        $store->users()->attach($manager, ['role_id' => $role->id, 'status' => 'active']);

        $response = $this->actingAs($manager)->postJson('/api/v1/developer/applications', ['name' => 'x']);

        $response->assertStatus(403);
    }

    public function test_store_a_cannot_manage_store_bs_application(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        $applicationB = DeveloperApplication::factory()->for($storeB)->create();

        $response = $this->actingAs($ownerA)->postJson("/api/v1/developer/applications/{$applicationB->id}/suspend");

        $response->assertStatus(404); // BelongsToTenant — Store A's context never sees Store B's application row
    }

    public function test_revoking_an_application_revokes_all_of_its_keys(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $application = DeveloperApplication::factory()->for($store)->create();
        $keyResponse = $this->actingAs($owner)->postJson("/api/v1/developer/applications/{$application->id}/keys", ['scopes' => ['products:read']]);
        $secret = $keyResponse->json('secret');

        $this->actingAs($owner)->deleteJson("/api/v1/developer/applications/{$application->id}")->assertStatus(204);

        $response = $this->withHeader('Authorization', "Bearer {$secret}")->getJson('/api/dev/v1/products');
        $response->assertStatus(401);
    }
}
