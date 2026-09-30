<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase B22 — Module 32 audit-log read APIs: a store sees only its own
 * chain, filters are whitelisted, and the Super Admin view spans every
 * store plus the platform chain.
 */
final class AuditLogApiTest extends TestCase
{
    use RefreshDatabase;

    private function owner(Store $store): User
    {
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);

        return $user;
    }

    /** @param list<string> $permissionKeys */
    private function staffWith(Store $store, array $permissionKeys): User
    {
        $role = Role::factory()->for($store)->create(['slug' => 'custom-'.uniqid()]);

        foreach ($permissionKeys as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['group' => 'compliance', 'description' => 'x']);
            DB::table('permission_role')->insert(['role_id' => $role->id, 'permission_id' => $permission->id]);
        }

        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_the_owner_lists_only_their_own_stores_entries_newest_first(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $logger = app(AuditLogger::class);
        $logger->record('orders.cancelled', ['order' => 'ORD-1'], storeId: $storeA->id);
        $logger->record('orders.refunded', ['order' => 'ORD-2'], storeId: $storeA->id);
        $logger->record('orders.cancelled', ['order' => 'ORD-B'], storeId: $storeB->id);
        $logger->record('super_admin.platform_action');

        $response = $this->actingAs($this->owner($storeA))->getJson('/api/v1/audit-logs');

        $response->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.data.0.action', 'orders.refunded')
            ->assertJsonPath('data.data.0.sequence', 2)
            ->assertJsonPath('data.data.1.context.order', 'ORD-1')
            ->assertJsonStructure(['data' => ['data' => [['id', 'sequence', 'action', 'actor' => ['type', 'id', 'label'], 'surface', 'subject', 'context', 'hash', 'created_at']]]]);
        $this->assertStringNotContainsString('ORD-B', $response->getContent());
    }

    public function test_filters_narrow_the_listing(): void
    {
        $store = Store::factory()->create();
        $owner = $this->owner($store);
        $customer = Customer::factory()->for($store)->create(['password' => Hash::make('customer-pass-99')]);
        $logger = app(AuditLogger::class);
        $logger->record('orders.cancelled', storeId: $store->id);
        $logger->record('orders.refunded', storeId: $store->id);
        $logger->record('auth.login.succeeded', subject: $customer, storeId: $store->id, actor: $customer);

        $this->actingAs($owner)->getJson('/api/v1/audit-logs?action=orders.')
            ->assertOk()->assertJsonPath('data.total', 2);

        $this->actingAs($owner)->getJson('/api/v1/audit-logs?actor_type=customer')
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.actor.id', $customer->public_id);

        $this->actingAs($owner)->getJson("/api/v1/audit-logs?actor={$customer->public_id}&subject_type=customer")
            ->assertOk()->assertJsonPath('data.total', 1);

        $this->actingAs($owner)->getJson('/api/v1/audit-logs?from='.now()->addDay()->toDateString())
            ->assertOk()->assertJsonPath('data.total', 0);

        // "%" in a filter is a literal character, never a wildcard.
        $this->actingAs($owner)->getJson('/api/v1/audit-logs?action=%25')
            ->assertOk()->assertJsonPath('data.total', 0);

        $this->actingAs($owner)->getJson('/api/v1/audit-logs?actor_type=robot&per_page=500')
            ->assertStatus(422)->assertJsonValidationErrors(['actor_type', 'per_page']);
    }

    public function test_the_store_integrity_check_reports_its_own_chain(): void
    {
        $store = Store::factory()->create();
        $owner = $this->owner($store);
        app(AuditLogger::class)->record('orders.cancelled', storeId: $store->id);

        $this->actingAs($owner)->getJson('/api/v1/audit-logs/integrity')
            ->assertOk()
            ->assertJsonPath('data.chain', "store:{$store->id}")
            ->assertJsonPath('data.status', 'ok');

        DB::table('audit_logs')->where('store_id', $store->id)->update(['action' => 'orders.nothing_to_see']);

        $this->actingAs($owner)->getJson('/api/v1/audit-logs/integrity')
            ->assertOk()->assertJsonPath('data.status', 'broken')->assertJsonPath('data.first_broken_sequence', 1);
    }

    public function test_reading_the_audit_log_requires_the_audit_view_permission(): void
    {
        $store = Store::factory()->create();
        $manager = User::factory()->create();
        $store->users()->attach($manager, ['role_id' => $this->systemRole($store, 'manager')->id, 'status' => 'active']);

        $this->actingAs($manager)->getJson('/api/v1/audit-logs')->assertForbidden();
        $this->actingAs($manager)->getJson('/api/v1/audit-logs/integrity')->assertForbidden();
        $this->actingAs($this->staffWith($store, ['audit.view']))->getJson('/api/v1/audit-logs')->assertOk();
    }

    public function test_a_customer_token_cannot_read_the_audit_log(): void
    {
        $store = Store::factory()->create();
        $token = Customer::factory()->for($store)->create(['password' => Hash::make('x')])->createToken('t')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/audit-logs')->assertUnauthorized();
    }

    public function test_the_super_admin_lists_across_stores_and_filters_by_store_or_platform(): void
    {
        $storeA = Store::factory()->create(['name' => 'Alpha Goods']);
        $storeB = Store::factory()->create();
        $logger = app(AuditLogger::class);
        $logger->record('orders.cancelled', storeId: $storeA->id);
        $logger->record('orders.cancelled', storeId: $storeB->id);
        $logger->record('super_admin.package.updated');
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $this->actingAs($superAdmin)->getJson("/api/v1/super-admin/audit-logs?store={$storeA->public_id}")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.store.name', 'Alpha Goods');

        // Reading the platform trail is itself a platform action, recorded before the listing runs.
        $platform = $this->actingAs($superAdmin)->getJson('/api/v1/super-admin/audit-logs?store=platform&action=super_admin.package')
            ->assertOk()->assertJsonPath('data.total', 1);
        $this->assertNull($platform->json('data.data.0.store'));

        $this->actingAs($superAdmin)->getJson('/api/v1/super-admin/audit-logs?store=01JUNKUNKNOWNSTOREPUBLICID')
            ->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_the_super_admin_integrity_check_covers_every_chain(): void
    {
        $store = Store::factory()->create();
        $logger = app(AuditLogger::class);
        $logger->record('orders.cancelled', storeId: $store->id);
        $logger->record('super_admin.package.updated');
        $superAdmin = User::factory()->create(['platform_role' => 'support_agent']);

        $response = $this->actingAs($superAdmin)->getJson('/api/v1/super-admin/audit-logs/integrity')->assertOk();
        $response->assertJsonPath('data.status', 'ok');
        $this->assertEqualsCanonicalizing(['platform', "store:{$store->id}"], array_column($response->json('data.chains'), 'chain'));

        DB::table('audit_logs')->where('store_id', $store->id)->update(['context' => '{"forged":true}']);

        $this->actingAs($superAdmin)->getJson('/api/v1/super-admin/audit-logs/integrity')
            ->assertOk()->assertJsonPath('data.status', 'broken');
    }

    public function test_store_staff_cannot_reach_the_platform_audit_log(): void
    {
        $store = Store::factory()->create();

        $this->actingAs($this->owner($store))->getJson('/api/v1/super-admin/audit-logs')->assertForbidden();
    }
}
