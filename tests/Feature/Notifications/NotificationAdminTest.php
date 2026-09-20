<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\NotificationTemplate;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase B11 — Staff template administration, publish-immutability,
 * tenant isolation, staff/customer boundary regression (Module 21
 * §12-14/§44 Rule #3).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class NotificationAdminTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Store $store): User
    {
        $role = Role::factory()->for($store)->create(['slug' => 'owner']);
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $role->id, 'status' => 'active']);

        return $user;
    }

    public function test_owner_can_create_a_template(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);

        $response = $this->actingAs($owner)->postJson('/api/v1/notification-templates', [
            'key' => 'order.created', 'channel' => 'email', 'subject' => 'Thanks!', 'body' => 'Hi {{customer.name}}',
        ]);

        $response->assertCreated();
    }

    public function test_editing_an_unpublished_template_is_allowed(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $template = NotificationTemplate::factory()->for($store)->create(['is_published' => false]);

        $response = $this->actingAs($owner)->putJson("/api/v1/notification-templates/{$template->id}", [
            'key' => $template->key, 'channel' => $template->channel->value, 'body' => 'Updated body',
        ]);

        $response->assertOk();
        $this->assertSame('Updated body', $template->fresh()->body);
    }

    public function test_editing_a_published_template_is_rejected(): void
    {
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $template = NotificationTemplate::factory()->for($store)->create(['is_published' => true]);

        $response = $this->actingAs($owner)->putJson("/api/v1/notification-templates/{$template->id}", [
            'key' => $template->key, 'channel' => $template->channel->value, 'body' => 'Should not save',
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'template_immutable');
        $this->assertNotSame('Should not save', $template->fresh()->body);
    }

    public function test_store_a_cannot_view_store_bs_templates(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $ownerA = $this->ownerOf($storeA);
        NotificationTemplate::factory()->for($storeB)->create(['key' => 'unique.key.b']);

        $response = $this->actingAs($ownerA)->getJson('/api/v1/notification-templates');

        $response->assertOk();
        $response->assertJsonMissing(['key' => 'unique.key.b']);
    }

    public function test_customer_token_cannot_access_staff_notification_routes(): void
    {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        $token = $customer->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/notification-templates');

        $response->assertStatus(401);
    }

    public function test_template_management_requires_permission(): void
    {
        $store = Store::factory()->create();
        $role = Role::factory()->for($store)->create(['slug' => 'no-notif-access']);
        $staff = User::factory()->create();
        $store->users()->attach($staff, ['role_id' => $role->id, 'status' => 'active']);

        $response = $this->actingAs($staff)->postJson('/api/v1/notification-templates', [
            'key' => 'x', 'channel' => 'email', 'body' => 'x',
        ]);

        $response->assertStatus(403);
    }
}
