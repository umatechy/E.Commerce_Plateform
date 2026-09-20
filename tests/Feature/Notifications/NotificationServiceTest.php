<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Notifications\Models\NotificationMessageType;
use App\Domain\Notifications\Models\NotificationSuppression;
use App\Domain\Notifications\Models\RecipientType;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Phase B11 — Notification orchestration: idempotency, consent/
 * suppression enforcement, mandatory-vs-marketing boundary (Module 21
 * §6/§9-10, Non-Negotiable).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_transactional_message_sends_regardless_of_marketing_opt_in(): void
    {
        Bus::fake();
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $customer = Customer::factory()->for($store)->create(['marketing_email_opt_in' => false]);

        $message = app(NotificationService::class)->send(
            NotificationMessageType::Transactional, NotificationChannel::Email,
            RecipientType::Customer, $customer->id, $customer->email,
            'Subject', 'Body', [], 'test-key-1',
        );

        $this->assertSame('queued', $message->status->value);
    }

    public function test_marketing_message_is_suppressed_when_customer_has_not_opted_in(): void
    {
        Bus::fake();
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $customer = Customer::factory()->for($store)->create(['marketing_email_opt_in' => false]);

        $message = app(NotificationService::class)->send(
            NotificationMessageType::Marketing, NotificationChannel::Email,
            RecipientType::Customer, $customer->id, $customer->email,
            'Subject', 'Body', [], 'test-key-2',
        );

        $this->assertSame('suppressed', $message->status->value);
    }

    public function test_marketing_message_sends_when_customer_has_opted_in(): void
    {
        Bus::fake();
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $customer = Customer::factory()->for($store)->create(['marketing_email_opt_in' => true]);

        $message = app(NotificationService::class)->send(
            NotificationMessageType::Marketing, NotificationChannel::Email,
            RecipientType::Customer, $customer->id, $customer->email,
            'Subject', 'Body', [], 'test-key-3',
        );

        $this->assertSame('queued', $message->status->value);
    }

    public function test_marketing_message_is_suppressed_when_destination_is_on_the_suppression_list(): void
    {
        Bus::fake();
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $customer = Customer::factory()->for($store)->create(['marketing_email_opt_in' => true, 'email' => 'suppressed@example.com']);
        NotificationSuppression::query()->create(['channel' => 'email', 'destination' => 'suppressed@example.com', 'reason' => 'unsubscribed']);

        $message = app(NotificationService::class)->send(
            NotificationMessageType::Marketing, NotificationChannel::Email,
            RecipientType::Customer, $customer->id, $customer->email,
            'Subject', 'Body', [], 'test-key-4',
        );

        $this->assertSame('suppressed', $message->status->value);
    }

    public function test_duplicate_idempotency_key_returns_the_same_message(): void
    {
        Bus::fake();
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $customer = Customer::factory()->for($store)->create();

        $first = app(NotificationService::class)->send(
            NotificationMessageType::Transactional, NotificationChannel::Email,
            RecipientType::Customer, $customer->id, $customer->email,
            'Subject', 'Body', [], 'same-key',
        );
        $second = app(NotificationService::class)->send(
            NotificationMessageType::Transactional, NotificationChannel::Email,
            RecipientType::Customer, $customer->id, $customer->email,
            'Subject', 'Body', [], 'same-key',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, NotificationMessage::query()->where('idempotency_key', 'same-key')->count());
    }

    public function test_a_guest_recipient_with_no_customer_record_is_never_eligible_for_marketing(): void
    {
        Bus::fake();
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        $message = app(NotificationService::class)->send(
            NotificationMessageType::Marketing, NotificationChannel::Email,
            RecipientType::Customer, null, 'guest@example.com',
            'Subject', 'Body', [], 'test-key-guest',
        );

        $this->assertSame('suppressed', $message->status->value);
    }

    public function test_template_variables_are_rendered_into_the_stored_message(): void
    {
        Bus::fake();
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $customer = Customer::factory()->for($store)->create();

        $message = app(NotificationService::class)->send(
            NotificationMessageType::Transactional, NotificationChannel::Email,
            RecipientType::Customer, $customer->id, $customer->email,
            'Order {{order.number}}', 'Hi {{customer.name}}',
            ['order.number' => 'ORD-000001', 'customer.name' => 'Jane'], 'test-key-render',
        );

        $this->assertSame('Order ORD-000001', $message->subject);
        $this->assertSame('Hi Jane', $message->body);
    }
}
