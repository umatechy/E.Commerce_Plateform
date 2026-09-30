<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Support\Models\SupportDesk;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase B26 — the store's support inbox for its team. */
final class StoreSupportInboxTest extends TestCase
{
    use InteractsWithSupport, RefreshDatabase;

    private function ticketFrom(Store $store, string $subject = 'Question', array $extra = []): string
    {
        return $this->postJson('/api/v1/customer/support/tickets', ['subject' => $subject, 'category' => 'other', 'message' => 'Hello', ...$extra], $this->as($this->customer($store)))
            ->assertCreated()->json('data.id');
    }

    public function test_a_public_reply_answers_assigns_and_waits_for_the_customer(): void
    {
        $store = $this->openStore();
        $agent = $this->staff($store, ['support.view', 'support.reply']);
        $id = $this->ticketFrom($store);

        $this->actingAs($agent)->postJson("/api/v1/support/tickets/{$id}/messages", ['body' => 'Happy to help.'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_customer')
            ->assertJsonPath('data.assignee.id', $agent->public_id);

        $ticket = SupportTicket::query()->sole();
        $this->assertNotNull($ticket->first_responded_at);

        // An internal note changes nothing the customer sees and restarts nothing.
        $this->actingAs($agent)->postJson("/api/v1/support/tickets/{$id}/messages", ['body' => 'VIP customer', 'internal' => true, 'status' => 'on_hold'])
            ->assertCreated()->assertJsonPath('data.messages.2.internal', true)->assertJsonPath('data.status', 'on_hold');
    }

    public function test_permissions_separate_reading_answering_and_managing(): void
    {
        $store = $this->openStore();
        $id = $this->ticketFrom($store);
        $reader = $this->staff($store, ['support.view']);
        $answerer = $this->staff($store, ['support.view', 'support.reply']);
        $outsider = $this->staff($store, ['orders.view']);

        $this->actingAs($outsider)->getJson('/api/v1/support/tickets')->assertForbidden();
        $this->actingAs($reader)->getJson("/api/v1/support/tickets/{$id}")->assertOk();
        // The inbox is told what to offer (the server still checks each call).
        $this->actingAs($reader)->getJson('/api/v1/support/summary')->assertOk()->assertJsonPath('data.abilities', ['reply' => false, 'manage' => false]);
        $this->actingAs($answerer)->getJson('/api/v1/support/summary')->assertOk()->assertJsonPath('data.abilities', ['reply' => true, 'manage' => false]);
        $this->actingAs($reader)->postJson("/api/v1/support/tickets/{$id}/messages", ['body' => 'x'])->assertForbidden();
        $this->actingAs($answerer)->patchJson("/api/v1/support/tickets/{$id}", ['status' => 'resolved'])->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->actingAs($answerer)->patchJson("/api/v1/support/tickets/{$id}", ['priority' => 'urgent'])->assertForbidden();
    }

    public function test_assigning_and_reprioritising_are_validated_and_audited(): void
    {
        $store = $this->openStore();
        $owner = $this->owner($store);
        $agent = $this->staff($store, ['support.reply']);
        $stranger = User::factory()->create();
        $id = $this->ticketFrom($store);

        $this->actingAs($owner)->getJson('/api/v1/support/agents')->assertOk()
            ->assertJsonFragment(['id' => $agent->public_id])->assertJsonMissing(['id' => $stranger->public_id]);
        $this->actingAs($owner)->patchJson("/api/v1/support/tickets/{$id}", ['assignee' => $stranger->public_id])
            ->assertStatus(422)->assertJsonValidationErrors('assignee');
        // A platform-desk topic does not fit a shopper's ticket.
        $this->actingAs($owner)->patchJson("/api/v1/support/tickets/{$id}", ['category' => 'billing'])
            ->assertStatus(422)->assertJsonValidationErrors('category');
        $this->actingAs($owner)->patchJson("/api/v1/support/tickets/{$id}", ['assignee' => $agent->public_id, 'priority' => 'urgent'])
            ->assertOk()->assertJsonPath('data.assignee.id', $agent->public_id)->assertJsonPath('data.priority', 'urgent');

        $ticket = SupportTicket::query()->sole();
        // The urgent first-response target is one hour from when the ticket was opened.
        $this->assertTrue($ticket->first_response_due_at->equalTo($ticket->created_at->copy()->addHour()));
        $entry = AuditLog::query()->where('action', 'support.ticket_updated')->sole();
        $this->assertSame('normal', $entry->contextData()['before']['priority']);
        $this->assertSame('urgent', $entry->contextData()['after']['priority']);
    }

    public function test_the_inbox_filters_and_orders_overdue_tickets_first(): void
    {
        $store = $this->openStore();
        $owner = $this->owner($store);
        $first = $this->ticketFrom($store, 'Parcel missing');
        $second = $this->ticketFrom($store, 'Wrong colour');
        SupportTicket::query()->where('public_id', $second)->update(['sla_breached_at' => now()]);
        $this->actingAs($owner)->patchJson("/api/v1/support/tickets/{$first}", ['assignee' => $owner->public_id])->assertOk();

        $this->actingAs($owner)->getJson('/api/v1/support/tickets')->assertOk()
            ->assertJsonPath('data.tickets.0.id', $second)->assertJsonPath('data.tickets.0.sla.breached', true);
        $this->actingAs($owner)->getJson('/api/v1/support/tickets?assignee=me')->assertJsonPath('data.pagination.total', 1);
        $this->actingAs($owner)->getJson('/api/v1/support/tickets?assignee=unassigned')->assertJsonPath('data.tickets.0.id', $second);
        $this->actingAs($owner)->getJson('/api/v1/support/tickets?breached=1')->assertJsonPath('data.pagination.total', 1);
        $this->actingAs($owner)->getJson('/api/v1/support/tickets?q=parcel')->assertJsonPath('data.tickets.0.id', $first);
        $this->actingAs($owner)->getJson('/api/v1/support/tickets?q=%25')->assertJsonPath('data.pagination.total', 0);

        $this->actingAs($owner)->getJson('/api/v1/support/summary')->assertOk()
            ->assertJsonPath('data.by_status.open', 2)
            ->assertJsonPath('data.unassigned', 1)
            ->assertJsonPath('data.breached', 1)
            ->assertJsonPath('data.mine', 1);
    }

    public function test_the_inbox_holds_only_this_stores_shopper_tickets(): void
    {
        $store = $this->openStore();
        $owner = $this->owner($store);
        $foreignId = $this->ticketFrom($this->openStore());
        $this->actingAs($owner)->postJson('/api/v1/platform-support/tickets', ['subject' => 'Invoice question', 'category' => 'billing', 'message' => 'Hi'])->assertCreated();

        $this->actingAs($owner)->getJson('/api/v1/support/tickets?status=all')->assertOk()->assertJsonPath('data.pagination.total', 0);
        $this->actingAs($owner)->getJson("/api/v1/support/tickets/{$foreignId}")->assertNotFound();
        $this->assertSame(1, SupportTicket::query()->withoutTenantScope()->where('desk', SupportDesk::Platform->value)->count());
    }

    public function test_service_figures_stay_empty_until_there_is_something_to_average(): void
    {
        $store = $this->openStore();
        $owner = $this->owner($store);
        $id = $this->ticketFrom($store);

        $this->actingAs($owner)->getJson('/api/v1/support/summary')->assertOk()
            ->assertJsonPath('data.last_30_days.created', 1)
            ->assertJsonPath('data.last_30_days.avg_first_response_minutes', null)
            ->assertJsonPath('data.last_30_days.satisfaction_avg', null);

        $this->travel(90)->minutes();
        $this->actingAs($owner)->postJson("/api/v1/support/tickets/{$id}/messages", ['body' => 'On it', 'status' => 'resolved'])->assertCreated();
        SupportTicket::query()->where('public_id', $id)->update(['satisfaction_rating' => 4]);

        $this->actingAs($owner)->getJson('/api/v1/support/summary')->assertOk()
            ->assertJsonPath('data.last_30_days.avg_first_response_minutes', 90)
            ->assertJsonPath('data.last_30_days.satisfaction_avg', 4);
    }
}
