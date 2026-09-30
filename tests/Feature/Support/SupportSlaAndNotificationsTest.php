<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Domain\Events\Models\OutboxEvent;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Support\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Phase B26 — service levels, auto-close, and the emails around a conversation. */
final class SupportSlaAndNotificationsTest extends TestCase
{
    use InteractsWithSupport, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /** @return list<string> subjects of the emails sent to $email */
    private function mailTo(string $email): array
    {
        return NotificationMessage::query()->withoutTenantScope()->where('destination', $email)->orderBy('id')->pluck('subject')->all();
    }

    public function test_missed_service_levels_are_flagged_once_and_answered_tickets_are_not(): void
    {
        $store = $this->openStore();
        $owner = $this->owner($store);
        $h = $this->as($this->customer($store));
        $late = $this->postJson('/api/v1/customer/support/tickets', ['subject' => 'Late', 'category' => 'other', 'message' => 'x'], $h)->json('data.id');
        $answered = $this->postJson('/api/v1/customer/support/tickets', ['subject' => 'Answered', 'category' => 'other', 'message' => 'x'], $h)->json('data.id');
        $this->actingAs($owner)->postJson("/api/v1/support/tickets/{$answered}/messages", ['body' => 'On it'])->assertCreated();

        $this->travel(25)->hours();
        $this->artisan('support:maintain')->assertSuccessful()->expectsOutputToContain('Flagged 1');
        $this->artisan('support:maintain')->assertSuccessful()->expectsOutputToContain('Flagged 0');

        $this->assertNotNull(SupportTicket::query()->where('public_id', $late)->value('sla_breached_at'));
        $this->assertNull(SupportTicket::query()->where('public_id', $answered)->value('sla_breached_at'));
        $this->assertSame(1, OutboxEvent::query()->withoutTenantScope()->where('event_type', 'support.sla_breached')->count());
    }

    public function test_resolved_tickets_close_after_the_reopen_window(): void
    {
        $store = $this->openStore();
        $h = $this->as($this->customer($store));
        $id = $this->postJson('/api/v1/customer/support/tickets', ['subject' => 'x', 'category' => 'other', 'message' => 'x'], $h)->json('data.id');
        $this->postJson("/api/v1/customer/support/tickets/{$id}/resolve", [], $h)->assertOk();

        $this->travel(6)->days();
        $this->artisan('support:maintain')->expectsOutputToContain('closed 0');
        $this->travel(2)->days();
        $this->artisan('support:maintain')->expectsOutputToContain('closed 1');

        $this->getJson("/api/v1/customer/support/tickets/{$id}", $h)->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.can_reply', false);
    }

    public function test_emails_follow_the_conversation_and_internal_notes_send_nothing(): void
    {
        $store = $this->openStore();
        $owner = $this->owner($store);
        $customer = $this->customer($store, ['email' => 'amna@example.com']);
        $h = $this->as($customer);
        $id = $this->postJson('/api/v1/customer/support/tickets', ['subject' => 'Where is my order', 'category' => 'order', 'message' => 'It has been a week'], $h)->json('data.id');
        $this->actingAs($owner)->postJson("/api/v1/support/tickets/{$id}/messages", ['body' => 'Checking with the courier', 'internal' => true])->assertCreated();
        $this->actingAs($owner)->postJson("/api/v1/support/tickets/{$id}/messages", ['body' => 'It arrives tomorrow.'])->assertCreated();
        $this->postJson("/api/v1/customer/support/tickets/{$id}/messages", ['body' => 'Thanks!'], $h)->assertCreated();

        $this->deliverEvents();
        $this->deliverEvents(); // a redelivery sends nothing twice

        $this->assertSame(['We received your request S-000001', 'Re: Where is my order [S-000001]'], $this->mailTo('amna@example.com'));
        $reply = NotificationMessage::query()->withoutTenantScope()->where('subject', 'Re: Where is my order [S-000001]')->sole();
        $this->assertStringContainsString('It arrives tomorrow.', $reply->body);
        $this->assertStringNotContainsString('courier', $reply->body);
        $this->assertSame(['New support request S-000001: Where is my order', 'New reply on S-000001: Where is my order'], $this->mailTo($owner->email));
    }

    public function test_message_text_is_escaped_in_emails(): void
    {
        $store = $this->openStore();
        $owner = $this->owner($store);
        $h = $this->as($this->customer($store, ['email' => 'amna@example.com']));
        $id = $this->postJson('/api/v1/customer/support/tickets', ['subject' => 'x', 'category' => 'other', 'message' => 'x'], $h)->json('data.id');
        $this->actingAs($owner)->postJson("/api/v1/support/tickets/{$id}/messages", ['body' => '<script>alert(1)</script> {{store.name}}'])->assertCreated();

        $this->deliverEvents();

        $body = NotificationMessage::query()->withoutTenantScope()->where('subject', 'like', 'Re:%')->sole()->body;
        $this->assertStringNotContainsString('<script>', $body);
        $this->assertStringContainsString('{{store.name}}', $body); // not expanded a second time
    }
}
