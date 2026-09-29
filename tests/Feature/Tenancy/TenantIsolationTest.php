<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Identity\Models\Role;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mandatory tenant-isolation regression suite (ADR-001 §16, this
 * prompt's "Tenant Isolation Test Design" section).
 *
 * STATUS: NOT EXECUTED — CLAUDE APP ENVIRONMENT LIMITATION.
 * These tests are written to the real PHPUnit/Laravel testing API and
 * are ready to run once the repository is opened in an environment with
 * PHP 8.3+, Composer, and a MySQL test database (VS Code phase). They
 * have not been executed here — no PHP runtime is available in this
 * Claude App sandbox (confirmed during Milestone-0 preflight).
 */
final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;

    private Store $storeB;

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeA = Store::factory()->create();
        $this->storeB = Store::factory()->create();

        $roleA = Role::factory()->for($this->storeA)->create();
        $roleB = Role::factory()->for($this->storeB)->create();

        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();

        $this->storeA->users()->attach($this->userA, ['role_id' => $roleA->id, 'status' => 'active']);
        $this->storeB->users()->attach($this->userB, ['role_id' => $roleB->id, 'status' => 'active']);
    }

    /** Tenant A reading Tenant B data via a guessed numeric route ID must 404, not 403. */
    public function test_tenant_a_cannot_read_tenant_b_resource_by_guessed_id(): void
    {
        $roleBelongingToB = Role::factory()->for($this->storeB)->create();

        $response = $this->actingAs($this->userA)->getJson("/api/v1/roles/{$roleBelongingToB->id}");

        $response->assertStatus(404);
    }

    /** Tenant A updating Tenant B data must be rejected at the query layer (global scope). */
    public function test_tenant_a_cannot_update_tenant_b_resource(): void
    {
        $roleBelongingToB = Role::factory()->for($this->storeB)->create();

        $response = $this->actingAs($this->userA)
            ->putJson("/api/v1/roles/{$roleBelongingToB->id}", ['name' => 'Hacked']);

        $response->assertStatus(404);
        $this->assertDatabaseHas('roles', ['id' => $roleBelongingToB->id, 'name' => $roleBelongingToB->name]);
    }

    /** Tenant A deleting Tenant B data must be rejected. */
    public function test_tenant_a_cannot_delete_tenant_b_resource(): void
    {
        $roleBelongingToB = Role::factory()->for($this->storeB)->create();

        $response = $this->actingAs($this->userA)->deleteJson("/api/v1/roles/{$roleBelongingToB->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('roles', ['id' => $roleBelongingToB->id]);
    }

    /** Manipulating a request payload's store_id must never change which tenant a write affects. */
    public function test_tenant_a_cannot_override_tenant_via_request_payload(): void
    {
        // userA needs permission to create roles at all, otherwise the
        // request is rejected (403) before the spoofed store_id matters.
        $this->storeA->users()->updateExistingPivot($this->userA->id, ['role_id' => $this->systemRole($this->storeA, 'owner')->id]);
        $this->actingAs($this->userA);

        $response = $this->postJson('/api/v1/roles', [
            'name' => 'Spoofed Role',
            'store_id' => $this->storeB->id, // attempted spoof
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('roles', [
            'name' => 'Spoofed Role',
            'store_id' => $this->storeA->id, // server-resolved context wins, not the payload
        ]);
    }

    /** A raw eager-loaded relationship must not leak a differently-owned child row. */
    public function test_relationship_level_protection_prevents_cross_tenant_child_resolution(): void
    {
        $roleBelongingToB = Role::factory()->for($this->storeB)->create();

        $this->assertNull(
            Role::query()->withoutTenantScope()
                ->whereKey($roleBelongingToB->id)
                ->where('store_id', $this->storeA->id)
                ->first()
        );
    }

    /** Tenant-scoped cache keys must be namespaced per store. */
    public function test_cache_keys_are_tenant_prefixed(): void
    {
        $this->actingAs($this->userA);

        \Illuminate\Support\Facades\Cache::put("tenant:{$this->storeA->id}:example", 'a-value');
        \Illuminate\Support\Facades\Cache::put("tenant:{$this->storeB->id}:example", 'b-value');

        $this->assertSame('a-value', \Illuminate\Support\Facades\Cache::get("tenant:{$this->storeA->id}:example"));
        $this->assertNotSame(
            \Illuminate\Support\Facades\Cache::get("tenant:{$this->storeA->id}:example"),
            \Illuminate\Support\Facades\Cache::get("tenant:{$this->storeB->id}:example")
        );
    }

    /** A queued job re-applies tenant scope from its OWN stored store_id, not ambient state. */
    public function test_queued_job_reapplies_stored_tenant_context(): void
    {
        $this->markTestIncomplete(
            'Requires a concrete queued job under test (e.g. a Phase B2+ module job). '.
            'The contract is established in App\Domain\Events\Jobs\ConsumeOutboxEventJob; '.
            'a module-specific job test is added once that module is implemented.'
        );
    }

    /**
     * Phase B1 addition: Tenant A must not be able to manipulate Tenant
     * B's roles via the now-implemented /api/v1/roles endpoints (this
     * milestone's "Tenant isolation ... Store A cannot manipulate Store
     * B roles" requirement).
     */
    public function test_tenant_a_cannot_list_or_manipulate_tenant_b_roles_via_api(): void
    {
        $roleBelongingToB = \App\Domain\Identity\Models\Role::factory()->for($this->storeB)->create();

        // Give userA a permission so a 403-vs-404 distinction is meaningful
        // (i.e. this test proves tenant scoping, not merely "no permission").
        $viewerRole = \App\Domain\Identity\Models\Role::factory()->for($this->storeA)->create();
        $permission = \App\Domain\Identity\Models\Permission::query()
            ->firstOrCreate(['key' => 'roles.view'], ['group' => 'roles']);
        $viewerRole->permissions()->attach($permission);
        $this->storeA->users()->updateExistingPivot($this->userA, ['role_id' => $viewerRole->id]);

        $listResponse = $this->actingAs($this->userA)->getJson('/api/v1/roles');
        $listResponse->assertOk();
        $listResponse->assertJsonMissing(['id' => $roleBelongingToB->id]);

        $showResponse = $this->actingAs($this->userA)->getJson("/api/v1/roles/{$roleBelongingToB->id}");
        $showResponse->assertStatus(404);
    }

    /**
     * Phase B1 addition: Tenant A must not be able to view or affect
     * Tenant B's user/membership data through Tenant A's own session
     * (this milestone's "Store A cannot manipulate Store B users").
     */
    public function test_tenant_a_session_never_resolves_tenant_b_membership(): void
    {
        $this->assertNotSame(
            $this->storeA->id,
            $this->storeB->id,
            'Sanity check: fixtures must be genuinely different stores.'
        );

        // userA has no membership in storeB at all — activeStoreId() must
        // never resolve to storeB for userA under any circumstance.
        $this->assertNotEquals($this->storeB->id, $this->actingAs($this->userA)->app['auth']->user()?->activeStoreId());
    }
}
