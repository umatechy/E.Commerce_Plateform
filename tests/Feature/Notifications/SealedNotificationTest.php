<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Jobs\DeliverNotificationJob;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Notifications\Models\NotificationMessageType;
use App\Domain\Notifications\Models\RecipientType;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Phase G1 — a secret in a notification is never stored readable and is gone once delivered. */
final class SealedNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function sendWithSecret(Store $store): NotificationMessage
    {
        app(TenantContext::class)->resolveToStore($store->id);

        return app(NotificationService::class)->send(
            NotificationMessageType::Security, NotificationChannel::Email, RecipientType::User, null, 'a@example.com',
            'Hello', 'Use {{link}} soon, {{name}}.', ['name' => 'Amna'], 'sealed-test:'.uniqid(), 'test',
            secretVariables: ['link' => 'https://example.com/x#token=SECRET123'],
        );
    }

    public function test_the_secret_is_encrypted_hidden_from_the_api_and_sent_in_the_email(): void
    {
        Queue::fake();
        $store = Store::factory()->create();
        $message = $this->sendWithSecret($store);

        $this->assertSame('Use [hidden] soon, Amna.', $message->body);
        $this->assertStringContainsString('SECRET123', $message->sealed_body);
        $this->assertStringNotContainsString('SECRET123', (string) DB::table('notification_messages')->value('sealed_body')); // encrypted at rest
        $this->assertArrayNotHasKey('sealed_body', $message->toArray());

        // The admin notification log shows only the hidden body.
        $manager = User::factory()->create();
        $store->users()->attach($manager, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);
        $this->actingAs($manager)->getJson('/api/v1/notification-messages')
            ->assertOk()->assertJsonPath('data.0.body', 'Use [hidden] soon, Amna.')->assertJsonMissingPath('data.0.sealed_body')
            ->assertDontSee('SECRET123');

        // Delivery sends the real link, then forgets it.
        app()->call([new DeliverNotificationJob($message->id), 'handle']);
        $sent = collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($m) => $m->getOriginalMessage()->getHtmlBody())->implode("\n");
        $this->assertStringContainsString('SECRET123', $sent);
        $this->assertNull($message->fresh()->sealed_body);
        $this->assertSame('sent', $message->fresh()->status->value);
    }
}
