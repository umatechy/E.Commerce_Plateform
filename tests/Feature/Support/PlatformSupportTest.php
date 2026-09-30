<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase B26 — a store's team ↔ the platform's support staff. */
final class PlatformSupportTest extends TestCase
{
    use InteractsWithSupport, RefreshDatabase;

    public function test_a_merchant_asks_the_platform_and_platform_staff_answer(): void
    {
        $store = $this->openStore();
        $store->update(['name' => 'Acme Outfitters']);
        $owner = $this->owner($store);
        $platformAgent = User::factory()->create(['platform_role' => 'support_agent', 'name' => 'Zara Platform']);

        $id = $this->actingAs($owner)->postJson('/api/v1/platform-support/tickets', ['subject' => 'Invoice looks wrong', 'category' => 'billing', 'message' => 'INV-000004 charges twice.'])
            ->assertCreated()->assertJsonPath('data.number', 'P-000001')->json('data.id');
        $this->actingAs($owner)->postJson('/api/v1/platform-support/tickets', ['subject' => 'x', 'category' => 'shipping', 'message' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('category'); // a store-desk category

        $this->actingAs($platformAgent)->getJson('/api/v1/super-admin/support/tickets')->assertOk()
            ->assertJsonPath('data.tickets.0.id', $id)
            ->assertJsonPath('data.tickets.0.store.name', 'Acme Outfitters')
            ->assertJsonPath('data.tickets.0.requester.email', $owner->email);

        $this->actingAs($platformAgent)->postJson("/api/v1/super-admin/support/tickets/{$id}/messages", ['body' => 'Refund issued.', 'status' => 'resolved'])
            ->assertCreated()->assertJsonPath('data.status', 'resolved');

        $this->actingAs($owner)->getJson("/api/v1/platform-support/tickets/{$id}")->assertOk()
            ->assertJsonPath('data.messages.1.author_name', 'Zara')
            ->assertJsonPath('data.can_rate', true);
    }

    public function test_platform_tickets_stay_within_their_store_and_platform_staff_are_the_only_assignees(): void
    {
        $storeA = $this->openStore();
        $ownerA = $this->owner($storeA);
        $storeB = $this->openStore();
        $ownerB = $this->owner($storeB);
        $manager = $this->staff($storeA, ['support.view', 'support.reply', 'support.manage']);
        $platformAgent = User::factory()->create(['platform_role' => 'support_agent']);
        $id = $this->actingAs($ownerA)->postJson('/api/v1/platform-support/tickets', ['subject' => 'Domain', 'category' => 'technical', 'message' => 'SSL?'])->json('data.id');

        $this->actingAs($ownerB)->getJson("/api/v1/platform-support/tickets/{$id}")->assertNotFound();
        $this->actingAs($manager)->getJson('/api/v1/platform-support/tickets')->assertForbidden(); // support.platform is the Owner's by default
        $this->actingAs($ownerA)->getJson('/api/v1/super-admin/support/tickets')->assertForbidden();

        $this->actingAs($platformAgent)->patchJson("/api/v1/super-admin/support/tickets/{$id}", ['assignee' => $ownerA->public_id])
            ->assertStatus(422)->assertJsonValidationErrors('assignee');
        $this->actingAs($platformAgent)->patchJson("/api/v1/super-admin/support/tickets/{$id}", ['assignee' => $platformAgent->public_id, 'priority' => 'high'])
            ->assertOk()->assertJsonPath('data.assignee.id', $platformAgent->public_id);
        $this->actingAs($platformAgent)->getJson('/api/v1/super-admin/support/tickets?store='.$storeB->public_id.'&status=all')
            ->assertOk()->assertJsonPath('data.pagination.total', 0);
    }
}
