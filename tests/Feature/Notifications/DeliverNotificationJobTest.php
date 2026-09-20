<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\Channels\NotificationChannelResolver;
use App\Domain\Notifications\Jobs\DeliverNotificationJob;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Notifications\Models\NotificationMessageType;
use App\Domain\Notifications\Models\NotificationStatus;
use App\Domain\Notifications\Models\RecipientType;
use App\Domain\Notifications\Services\NotificationStateMachine;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B11 — Delivery execution: in-app completes fully, stub
 * channels fail permanently without retrying, terminal-state re-run
 * guard (Module 21 §23/§29-30).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class DeliverNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    private function queuedMessage(Store $store, NotificationChannel $channel): NotificationMessage
    {
        return NotificationMessage::query()->create([
            'message_type' => NotificationMessageType::Transactional, 'channel' => $channel,
            'recipient_type' => RecipientType::Customer, 'recipient_id' => 1, 'destination' => 'test@example.com',
            'subject' => 'S', 'body' => 'B', 'status' => NotificationStatus::Queued,
            'idempotency_key' => uniqid(),
        ]);
    }

    public function test_in_app_message_is_marked_delivered_immediately(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $message = $this->queuedMessage($store, NotificationChannel::InApp);

        (new DeliverNotificationJob($message->id))->handle(app(TenantContext::class), app(NotificationChannelResolver::class), app(NotificationStateMachine::class));

        $this->assertSame('delivered', $message->fresh()->status->value);
    }

    public function test_stub_sms_channel_fails_permanently_without_retry(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $message = $this->queuedMessage($store, NotificationChannel::Sms);

        (new DeliverNotificationJob($message->id))->handle(app(TenantContext::class), app(NotificationChannelResolver::class), app(NotificationStateMachine::class));

        $this->assertSame('failed', $message->fresh()->status->value);
        $this->assertDatabaseHas('notification_delivery_attempts', ['notification_message_id' => $message->id, 'failure_code' => 'channel_not_configured']);
    }

    public function test_rerunning_the_job_on_an_already_terminal_message_is_a_safe_no_op(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $message = $this->queuedMessage($store, NotificationChannel::InApp);

        $job = new DeliverNotificationJob($message->id);
        $job->handle(app(TenantContext::class), app(NotificationChannelResolver::class), app(NotificationStateMachine::class));
        $job->handle(app(TenantContext::class), app(NotificationChannelResolver::class), app(NotificationStateMachine::class)); // simulated duplicate dispatch

        $this->assertSame(1, $message->attempts()->count());
    }

    public function test_email_channel_records_a_successful_delivery_attempt(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $message = $this->queuedMessage($store, NotificationChannel::Email);

        (new DeliverNotificationJob($message->id))->handle(app(TenantContext::class), app(NotificationChannelResolver::class), app(NotificationStateMachine::class));

        $this->assertSame('sent', $message->fresh()->status->value);
        $this->assertDatabaseHas('notification_delivery_attempts', ['notification_message_id' => $message->id, 'result' => 'succeeded', 'provider' => 'smtp']);
    }
}
