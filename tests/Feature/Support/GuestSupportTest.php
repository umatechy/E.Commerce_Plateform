<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Orders\Models\Order;
use App\Domain\Support\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Phase B26 — the storefront contact form and a guest's private ticket link. */
final class GuestSupportTest extends TestCase
{
    use InteractsWithSupport, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function contact(array $overrides = []): array
    {
        return ['name' => 'Guest Buyer', 'email' => 'Guest@Example.com', 'subject' => 'Damaged item', 'category' => 'returns', 'message' => 'The mug arrived broken.', ...$overrides];
    }

    public function test_a_guest_gets_a_ticket_and_a_private_link_by_email(): void
    {
        $store = $this->openStore();
        $h = ['X-Store-Slug' => $store->slug];

        $response = $this->postJson('/api/v1/storefront/support/contact', $this->contact(), $h)->assertCreated();
        $token = $response->json('data.access_token');
        $id = $response->json('data.id');

        $ticket = SupportTicket::query()->sole();
        $this->assertSame('guest', $ticket->requester_type);
        $this->assertSame('guest@example.com', $ticket->requester_email);
        $this->assertNotSame($token, $ticket->guest_token_hash); // only a hash is stored
        $mail = NotificationMessage::query()->withoutTenantScope()->where('destination', 'guest@example.com')->sole();
        // The token rides in the #fragment, which browsers never send to a server. Only the sealed
        // text the delivery job sends carries it; the stored, admin-readable body shows "[hidden]" (Phase G1).
        $this->assertStringContainsString("support/tickets/{$id}#token={$token}", $mail->sealed_body);
        $this->assertStringNotContainsString($token, $mail->body);
        $this->assertStringContainsString('[hidden]', $mail->body);

        $this->getJson("/api/v1/storefront/support/tickets/{$id}", [...$h, 'X-Support-Token' => $token])->assertOk()->assertJsonPath('data.messages.0.body', 'The mug arrived broken.');
        $this->getJson("/api/v1/storefront/support/tickets/{$id}?token={$token}", $h)->assertOk();
        $this->getJson("/api/v1/storefront/support/tickets/{$id}", [...$h, 'X-Support-Token' => str_repeat('x', 48)])->assertNotFound();
        $this->getJson("/api/v1/storefront/support/tickets/{$id}", $h)->assertNotFound();
        $this->postJson("/api/v1/storefront/support/tickets/{$id}/messages", ['body' => 'Photo attached later', 'token' => $token], $h)->assertCreated();
    }

    public function test_bots_filling_the_honeypot_are_refused(): void
    {
        $store = $this->openStore();

        $this->postJson('/api/v1/storefront/support/contact', $this->contact(['website' => 'http://spam.example']), ['X-Store-Slug' => $store->slug])
            ->assertStatus(422);
        $this->assertSame(0, SupportTicket::query()->count());
    }

    public function test_an_order_is_linked_only_when_its_email_matches(): void
    {
        $store = $this->openStore();
        $order = Order::factory()->create(['store_id' => $store->id, 'guest_email' => 'guest@example.com', 'order_number' => 'ORD-777001']);
        Order::factory()->create(['store_id' => $store->id, 'guest_email' => 'someone@example.com', 'order_number' => 'ORD-777002']);
        $h = ['X-Store-Slug' => $store->slug];

        $this->postJson('/api/v1/storefront/support/contact', $this->contact(['order_number' => 'ORD-777001']), $h)->assertCreated();
        $this->postJson('/api/v1/storefront/support/contact', $this->contact(['order_number' => 'ORD-777002']), $h)->assertCreated();

        $this->assertSame([$order->id, null], SupportTicket::query()->orderBy('id')->pluck('order_id')->all());
    }

    public function test_a_signed_in_customer_using_the_form_gets_an_account_ticket(): void
    {
        $store = $this->openStore();
        $customer = $this->customer($store);

        $this->postJson('/api/v1/storefront/support/contact', $this->contact(), ['X-Store-Slug' => $store->slug, ...$this->as($customer)])
            ->assertCreated()->assertJsonPath('data.access_token', null);

        $this->assertSame(['customer', $customer->id], [SupportTicket::query()->sole()->requester_type, SupportTicket::query()->sole()->requester_id]);
    }
}
