<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Customers\Models\CustomerGroup;
use App\Domain\Customers\Models\CustomerNote;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase B32 (gap G7, Module 10): the staff customer API — list, filters,
 * detail, groups, tags, notes, status, activity — with its permissions
 * and tenant isolation.
 */
final class CustomerAdminTest extends TestCase
{
    use RefreshDatabase;

    private function owner(Store $store): User
    {
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);

        return $user;
    }

    /** @param list<string> $keys */
    private function staffWith(Store $store, array $keys): User
    {
        $role = Role::factory()->for($store)->create(['slug' => 'custom-'.uniqid()]);
        foreach ($keys as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['group' => 'customers', 'description' => 'x']);
            DB::table('permission_role')->insert(['role_id' => $role->id, 'permission_id' => $permission->id]);
        }
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    private function customer(Store $store, array $attributes = []): Customer
    {
        return Customer::factory()->for($store)->create(['password' => Hash::make('customer-pass-99'), ...$attributes]);
    }

    public function test_the_list_shows_figures_and_filters_by_status_group_tag_and_orders(): void
    {
        $store = Store::factory()->create();
        $owner = $this->owner($store);
        $ayesha = $this->customer($store, ['name' => 'Ayesha Khan', 'email' => 'ayesha@example.com']);
        $bilal = $this->customer($store, ['name' => 'Bilal Ahmed', 'email' => 'bilal@example.com']);
        Order::factory()->create(['store_id' => $store->id, 'customer_id' => $ayesha->id, 'status' => OrderStatus::Completed, 'grand_total_minor' => 5000]);
        Order::factory()->create(['store_id' => $store->id, 'customer_id' => $ayesha->id, 'status' => OrderStatus::Completed, 'grand_total_minor' => 3000]);
        // A cancelled order does not count.
        Order::factory()->create(['store_id' => $store->id, 'customer_id' => $ayesha->id, 'status' => OrderStatus::Cancelled, 'grand_total_minor' => 9999]);

        $list = $this->actingAs($owner)->getJson('/api/v1/customers?sort=spent');
        $list->assertOk()->assertJsonPath('data.data.0.id', $ayesha->public_id)
            ->assertJsonPath('data.data.0.orders_count', 2)
            ->assertJsonPath('data.data.0.total_spent_minor', 8000);

        $this->actingAs($owner)->getJson('/api/v1/customers?min_orders=1')->assertOk()->assertJsonCount(1, 'data.data');
        $this->actingAs($owner)->getJson('/api/v1/customers?search=bilal')->assertOk()->assertJsonPath('data.data.0.id', $bilal->public_id)->assertJsonCount(1, 'data.data');

        $group = $this->actingAs($owner)->postJson('/api/v1/customer-groups', ['name' => 'Wholesale'])->assertCreated()->json('data.id');
        $this->actingAs($owner)->patchJson("/api/v1/customers/{$bilal->public_id}", ['group' => $group])->assertOk()->assertJsonPath('data.group.name', 'Wholesale');
        $this->actingAs($owner)->putJson("/api/v1/customers/{$bilal->public_id}/tags", ['tags' => ['VIP', 'Lahore']])->assertOk()->assertJsonCount(2, 'data.tags');

        $this->actingAs($owner)->getJson("/api/v1/customers?group={$group}")->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $bilal->public_id);
        $this->actingAs($owner)->getJson('/api/v1/customers?group=none')->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $ayesha->public_id);
        $vip = collect($this->actingAs($owner)->getJson('/api/v1/customer-tags')->assertOk()->json('data'))->firstWhere('name', 'VIP');
        $this->actingAs($owner)->getJson("/api/v1/customers?tag={$vip['id']}")->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $bilal->public_id);

        $this->actingAs($owner)->postJson("/api/v1/customers/{$ayesha->public_id}/block", ['reason' => 'Chargebacks'])->assertOk()->assertJsonPath('data.status', 'blocked');
        $this->actingAs($owner)->getJson('/api/v1/customers?status=blocked')->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $ayesha->public_id);
    }

    public function test_another_stores_customer_is_not_found_anywhere(): void
    {
        $store = Store::factory()->create();
        $other = Store::factory()->create();
        $owner = $this->owner($store);
        $theirs = $this->customer($other, ['email' => 'theirs@example.com']);

        $this->actingAs($owner)->getJson("/api/v1/customers/{$theirs->public_id}")->assertNotFound();
        $this->actingAs($owner)->postJson("/api/v1/customers/{$theirs->public_id}/block", ['reason' => 'x'])->assertNotFound();
        $this->actingAs($owner)->getJson("/api/v1/customers/{$theirs->public_id}/notes")->assertNotFound();
        $this->actingAs($owner)->getJson('/api/v1/customers?search=theirs')->assertOk()->assertJsonCount(0, 'data.data');

        $theirGroup = CustomerGroup::query()->withoutTenantScope()->create(['store_id' => $other->id, 'name' => 'Theirs']);
        $this->actingAs($owner)->deleteJson("/api/v1/customer-groups/{$theirGroup->public_id}")->assertNotFound();
        $this->assertDatabaseHas('customer_groups', ['id' => $theirGroup->id]);
    }

    public function test_permissions_view_manage_export_import_are_separate(): void
    {
        $store = Store::factory()->create();
        $viewer = $this->staffWith($store, ['customers.view']);
        $nobody = $this->staffWith($store, ['orders.view']);
        $customer = $this->customer($store);

        $this->actingAs($nobody)->getJson('/api/v1/customers')->assertForbidden();
        $this->actingAs($viewer)->getJson('/api/v1/customers')->assertOk();
        $this->actingAs($viewer)->getJson("/api/v1/customers/{$customer->public_id}")->assertOk();
        $this->actingAs($viewer)->postJson("/api/v1/customers/{$customer->public_id}/block", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/v1/customers/{$customer->public_id}/notes", ['body' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/v1/customers', ['name' => 'A', 'email' => 'a@example.com'])->assertForbidden();
        $this->actingAs($viewer)->get('/api/v1/customers/export')->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/v1/customer-groups', ['name' => 'X'])->assertForbidden();
    }

    public function test_staff_can_add_a_customer_but_not_a_duplicate_and_not_change_a_registered_email(): void
    {
        $store = Store::factory()->create();
        $owner = $this->owner($store);

        $created = $this->actingAs($owner)->postJson('/api/v1/customers', ['name' => 'Walk In', 'email' => 'walkin@example.com', 'phone' => '+92 300 1234567'])
            ->assertCreated()->assertJsonPath('data.source', 'staff')->assertJsonPath('data.registered', false);
        $this->actingAs($owner)->postJson('/api/v1/customers', ['name' => 'Again', 'email' => 'WALKIN@example.com'])->assertUnprocessable()->assertJsonValidationErrors('email');

        // Without an account staff may correct the email...
        $this->actingAs($owner)->patchJson('/api/v1/customers/'.$created->json('data.id'), ['email' => 'walk.in@example.com'])->assertOk()->assertJsonPath('data.email', 'walk.in@example.com');

        // ...with one, only the customer can.
        $registered = $this->customer($store, ['email' => 'mine@example.com']);
        $this->actingAs($owner)->patchJson("/api/v1/customers/{$registered->public_id}", ['email' => 'hijack@example.com'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame('mine@example.com', $registered->fresh()->email);
    }

    public function test_notes_are_for_staff_only_and_their_text_stays_out_of_the_audit(): void
    {
        $store = Store::factory()->create();
        $owner = $this->owner($store);
        $customer = $this->customer($store);

        $note = $this->actingAs($owner)->postJson("/api/v1/customers/{$customer->public_id}/notes", ['body' => 'Prefers evening delivery'])->assertCreated();
        $this->actingAs($owner)->getJson("/api/v1/customers/{$customer->public_id}/notes")->assertOk()->assertJsonPath('data.0.body', 'Prefers evening delivery')->assertJsonPath('data.0.is_yours', true);

        $entry = AuditLog::query()->where('action', 'customer.note_added')->sole();
        $this->assertStringNotContainsString('evening', (string) $entry->getRawOriginal('context'));

        // The customer's own API never shows notes.
        $this->app['auth']->forgetGuards();
        $token = $customer->createToken('t')->plainTextToken;
        $profile = $this->getJson('/api/v1/customer/profile', ['Authorization' => "Bearer {$token}", 'X-Store-Slug' => $store->slug]);
        $profile->assertOk();
        $this->assertStringNotContainsString('evening', (string) $profile->getContent());
    }

    public function test_a_note_can_be_deleted_and_only_its_id_is_audited(): void
    {
        $store = Store::factory()->create();
        $owner = $this->owner($store);
        $customer = $this->customer($store);
        $other = $this->customer($store);

        $note = $this->actingAs($owner)->postJson("/api/v1/customers/{$customer->public_id}/notes", ['body' => 'Private remark'])->assertCreated()->json('data.id');
        // Through another customer's address the note is not found.
        $this->actingAs($owner)->deleteJson("/api/v1/customers/{$other->public_id}/notes/{$note}")->assertNotFound();
        $this->actingAs($owner)->deleteJson("/api/v1/customers/{$customer->public_id}/notes/{$note}")->assertNoContent();

        $this->assertSame(0, CustomerNote::query()->withoutTenantScope()->count());
        $entry = AuditLog::query()->where('action', 'customer.note_deleted')->sole();
        $this->assertStringNotContainsString('Private remark', (string) $entry->getRawOriginal('context'));
    }

    public function test_blocking_needs_a_reason_ends_sessions_and_unblocking_restores(): void
    {
        $store = Store::factory()->create();
        $owner = $this->owner($store);
        $customer = $this->customer($store);
        $customer->createToken('t');

        $this->actingAs($owner)->postJson("/api/v1/customers/{$customer->public_id}/block", [])->assertUnprocessable();
        $this->actingAs($owner)->postJson("/api/v1/customers/{$customer->public_id}/block", ['reason' => 'Abuse'])->assertOk();
        $this->assertSame(0, $customer->tokens()->count());
        // Archiving a blocked customer would hide the block.
        $this->actingAs($owner)->postJson("/api/v1/customers/{$customer->public_id}/archive", [])->assertStatus(409);

        $this->actingAs($owner)->postJson("/api/v1/customers/{$customer->public_id}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->actingAs($owner)->postJson("/api/v1/customers/{$customer->public_id}/reactivate")->assertStatus(409);

        $actions = AuditLog::query()->where('subject_type', 'customer')->where('subject_id', $customer->id)->pluck('action')->all();
        $this->assertContains('customer.blocked', $actions);
        $this->assertContains('customer.unblocked', $actions);

        $activity = $this->actingAs($owner)->getJson("/api/v1/customers/{$customer->public_id}/activity")->assertOk();
        $blocked = collect($activity->json('data'))->firstWhere('type', 'customer.blocked');
        $this->assertSame('Abuse', $blocked['details']['reason']);
        $this->assertArrayNotHasKey('ip', $blocked['details']);
    }

    public function test_the_detail_warns_of_possible_duplicates_without_merging(): void
    {
        $store = Store::factory()->create();
        $owner = $this->owner($store);
        $one = $this->customer($store, ['email' => 'same@example.com', 'phone' => '+923001234567']);
        $guest = Customer::factory()->for($store)->create(['email' => 'SAME@example.com', 'password' => null]);

        $detail = $this->actingAs($owner)->getJson("/api/v1/customers/{$one->public_id}")->assertOk();
        $detail->assertJsonPath('data.possible_duplicates.0.id', $guest->public_id)->assertJsonPath('data.possible_duplicates.0.reason', 'same_email');
        $this->assertSame(2, Customer::query()->withoutTenantScope()->where('store_id', $store->id)->count());
    }

    public function test_a_group_name_is_unique_per_store_and_deleting_it_keeps_the_customers(): void
    {
        $store = Store::factory()->create();
        $other = Store::factory()->create();
        $owner = $this->owner($store);
        CustomerGroup::query()->withoutTenantScope()->create(['store_id' => $other->id, 'name' => 'Retail']);

        $group = $this->actingAs($owner)->postJson('/api/v1/customer-groups', ['name' => 'Retail'])->assertCreated()->json('data.id');
        $this->actingAs($owner)->postJson('/api/v1/customer-groups', ['name' => 'Retail'])->assertUnprocessable();

        $customer = $this->customer($store);
        $this->actingAs($owner)->patchJson("/api/v1/customers/{$customer->public_id}", ['group' => $group])->assertOk();
        $this->actingAs($owner)->deleteJson("/api/v1/customer-groups/{$group}")->assertNoContent();

        $this->assertNull($customer->fresh()->customer_group_id);
        $this->assertNotNull($customer->fresh());
    }

    public function test_the_admin_customer_page_route_and_groups_page_render(): void
    {
        $store = Store::factory()->create();
        $owner = $this->owner($store);
        $customer = $this->customer($store);
        app(TenantContext::class)->resolveToStore($store->id);

        // No Vite manifest in CI: render the page without assets.
        $this->withoutVite()->actingAs($owner)->get("/customers/{$customer->public_id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Customers/Show')->where('customerId', $customer->public_id));
        $this->withoutVite()->actingAs($owner)->get('/customers/groups')->assertOk()->assertInertia(fn ($page) => $page->component('Customers/Groups'));
    }
}
