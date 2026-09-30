<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Domain\Orders\Models\Order;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase B26 — a signed-in customer's requests to their store. */
final class CustomerSupportTest extends TestCase
{
    use InteractsWithSupport, RefreshDatabase;

    public function test_a_customer_opens_a_ticket_about_their_own_order(): void
    {
        $store = $this->openStore();
        $customer = $this->customer($store, ['name' => 'Amna Rauf']);
        $order = Order::factory()->create(['store_id' => $store->id, 'customer_id' => $customer->id]);
        $foreign = Order::factory()->create(['store_id' => $store->id, 'customer_id' => $this->customer($store)->id]);
        $h = $this->as($customer);

        $this->postJson('/api/v1/customer/support/tickets', ['subject' => 'Late parcel', 'category' => 'shipping', 'message' => 'Where is it?', 'order' => $foreign->public_id], $h)
            ->assertStatus(422)->assertJsonValidationErrors('order');
        $this->postJson('/api/v1/customer/support/tickets', ['subject' => 'Refund', 'category' => 'billing', 'message' => 'x'], $h)
            ->assertStatus(422)->assertJsonValidationErrors('category'); // a platform-desk category

        $response = $this->postJson('/api/v1/customer/support/tickets', ['subject' => 'Late parcel', 'category' => 'shipping', 'message' => 'Where is it?', 'order' => $order->public_id], $h);

        $response->assertCreated()
            ->assertJsonPath('data.number', 'S-000001')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.order.number', $order->order_number)
            ->assertJsonPath('data.messages.0.body', 'Where is it?')
            ->assertJsonMissingPath('data.assignee')
            ->assertJsonMissingPath('data.sla')
            ->assertJsonMissingPath('data.order.grand_total_minor');
        $ticket = SupportTicket::query()->sole();
        $this->assertNotNull($ticket->first_response_due_at);
        $this->assertTrue($ticket->first_response_due_at->between(now()->addHours(23), now()->addHours(25)));
    }

    public function test_customers_only_ever_see_their_own_tickets(): void
    {
        $store = $this->openStore();
        $amna = $this->customer($store);
        $bilal = $this->customer($store);
        $id = $this->postJson('/api/v1/customer/support/tickets', ['subject' => 'Mine', 'category' => 'other', 'message' => 'Hi'], $this->as($amna))->json('data.id');

        $this->getJson('/api/v1/customer/support/tickets', $this->as($bilal))->assertOk()->assertJsonPath('data.pagination.total', 0);
        $this->getJson("/api/v1/customer/support/tickets/{$id}", $this->as($bilal))->assertNotFound();
        $this->postJson("/api/v1/customer/support/tickets/{$id}/messages", ['body' => 'hijack'], $this->as($bilal))->assertNotFound();
        $this->getJson('/api/v1/customer/support/tickets', $this->as($amna))->assertOk()->assertJsonPath('data.tickets.0.subject', 'Mine');
    }

    public function test_the_conversation_hides_internal_notes_and_agent_surnames(): void
    {
        $store = $this->openStore();
        $customer = $this->customer($store);
        $agent = $this->owner($store);
        $agent->update(['name' => 'Sara Ahmed']);
        $h = $this->as($customer);
        $id = $this->postJson('/api/v1/customer/support/tickets', ['subject' => 'Size', 'category' => 'product', 'message' => 'Does it run small?'], $h)->json('data.id');

        $this->actingAs($agent)->postJson("/api/v1/support/tickets/{$id}/messages", ['body' => 'Customer seems upset', 'internal' => true])->assertCreated();
        $this->actingAs($agent)->postJson("/api/v1/support/tickets/{$id}/messages", ['body' => 'It runs true to size.'])->assertCreated();

        $view = $this->getJson("/api/v1/customer/support/tickets/{$id}", $h)->assertOk();
        $view->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.1.author_name', 'Sara')
            ->assertJsonPath('data.status', 'awaiting_customer');
        $this->assertStringNotContainsString('upset', $view->getContent());
        $this->assertStringNotContainsString('Ahmed', $view->getContent());
    }

    public function test_replying_reopens_resolving_and_rating_happen_once(): void
    {
        $store = $this->openStore();
        $h = $this->as($this->customer($store));
        $id = $this->postJson('/api/v1/customer/support/tickets', ['subject' => 'Q', 'category' => 'other', 'message' => 'Hi'], $h)->json('data.id');

        $this->postJson("/api/v1/customer/support/tickets/{$id}/rating", ['rating' => 5], $h)->assertStatus(409)->assertJsonPath('code', 'not_resolved');
        $this->postJson("/api/v1/customer/support/tickets/{$id}/resolve", [], $h)->assertOk()->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.can_rate', true);
        $this->postJson("/api/v1/customer/support/tickets/{$id}/rating", ['rating' => 5, 'comment' => 'Quick!'], $h)->assertOk()->assertJsonPath('data.satisfaction.rating', 5);
        $this->postJson("/api/v1/customer/support/tickets/{$id}/rating", ['rating' => 1], $h)->assertStatus(409)->assertJsonPath('code', 'already_rated');

        // A reply within the reopen window reopens it for the team.
        $this->postJson("/api/v1/customer/support/tickets/{$id}/messages", ['body' => 'One more thing'], $h)->assertCreated()->assertJsonPath('data.status', 'open');

        $this->postJson("/api/v1/customer/support/tickets/{$id}/resolve", [], $h)->assertOk();
        $this->travel(8)->days();
        $this->postJson("/api/v1/customer/support/tickets/{$id}/messages", ['body' => 'Late'], $h)->assertStatus(409)->assertJsonPath('code', 'ticket_closed');
    }

    public function test_numbers_run_per_store_and_open_requests_are_capped(): void
    {
        config(['support.max_open_tickets_per_requester' => 2]);
        $storeA = $this->openStore();
        $h = $this->as($this->customer($storeA));
        $open = fn () => $this->postJson('/api/v1/customer/support/tickets', ['subject' => 'Q', 'category' => 'other', 'message' => 'Hi'], $h);

        $open()->assertCreated()->assertJsonPath('data.number', 'S-000001');
        $second = $open()->assertCreated()->assertJsonPath('data.number', 'S-000002')->json('data.id');
        $open()->assertStatus(422)->assertJsonValidationErrors('subject');

        // A resolved request no longer counts against the cap.
        $this->postJson("/api/v1/customer/support/tickets/{$second}/resolve", [], $h)->assertOk();
        $open()->assertCreated()->assertJsonPath('data.number', 'S-000003');

        $storeB = $this->openStore();
        $this->postJson('/api/v1/customer/support/tickets', ['subject' => 'Q', 'category' => 'other', 'message' => 'Hi'], $this->as($this->customer($storeB)))
            ->assertCreated()->assertJsonPath('data.number', 'S-000001');
        app(TenantContext::class)->resolveToStore($storeA->id);
        $this->assertSame(3, SupportTicket::query()->count());
    }
}
