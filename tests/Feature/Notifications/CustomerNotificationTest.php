<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Notifications\Models\RecipientType;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase B11 — In-app notification retrieval/read-state, customer
 * preference toggle (Module 21 §11/§21).
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class CustomerNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(Customer $customer): string
    {
        return $customer->createToken('t')->plainTextToken;
    }

    public function test_customer_can_list_their_own_in_app_notifications(): void
    {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        NotificationMessage::query()->create([
            'store_id' => $store->id, 'message_type' => 'transactional', 'channel' => 'in_app',
            'recipient_type' => RecipientType::Customer, 'recipient_id' => $customer->id, 'destination' => 'n/a',
            'subject' => null, 'body' => 'Hello', 'status' => 'delivered', 'idempotency_key' => uniqid(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($customer))->getJson('/api/v1/customer/notifications');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_customer_cannot_see_another_customers_notifications(): void
    {
        $store = Store::factory()->create();
        $customerA = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        $customerB = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        NotificationMessage::query()->create([
            'store_id' => $store->id, 'message_type' => 'transactional', 'channel' => 'in_app',
            'recipient_type' => RecipientType::Customer, 'recipient_id' => $customerB->id, 'destination' => 'n/a',
            'body' => 'Private to B', 'status' => 'delivered', 'idempotency_key' => uniqid(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($customerA))->getJson('/api/v1/customer/notifications');

        $response->assertOk();
        $response->assertJsonMissing(['body' => 'Private to B']);
    }

    public function test_marking_a_notification_read_updates_its_state(): void
    {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        $message = NotificationMessage::query()->create([
            'store_id' => $store->id, 'message_type' => 'transactional', 'channel' => 'in_app',
            'recipient_type' => RecipientType::Customer, 'recipient_id' => $customer->id, 'destination' => 'n/a',
            'body' => 'Hello', 'status' => 'delivered', 'idempotency_key' => uniqid(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($customer))
            ->postJson("/api/v1/customer/notifications/{$message->id}/read");

        $response->assertOk();
        $this->assertNotNull($message->fresh()->read_at);
    }

    public function test_customer_cannot_mark_another_customers_notification_read(): void
    {
        $store = Store::factory()->create();
        $customerA = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        $customerB = Customer::factory()->for($store)->create(['password' => Hash::make('x')]);
        $message = NotificationMessage::query()->create([
            'store_id' => $store->id, 'message_type' => 'transactional', 'channel' => 'in_app',
            'recipient_type' => RecipientType::Customer, 'recipient_id' => $customerB->id, 'destination' => 'n/a',
            'body' => 'Hello', 'status' => 'delivered', 'idempotency_key' => uniqid(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($customerA))
            ->postJson("/api/v1/customer/notifications/{$message->id}/read");

        $response->assertStatus(404);
        $this->assertNull($message->fresh()->read_at);
    }

    public function test_customer_can_update_marketing_email_preference(): void
    {
        $store = Store::factory()->create();
        $customer = Customer::factory()->for($store)->create(['password' => Hash::make('x'), 'marketing_email_opt_in' => false]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($customer))
            ->patchJson('/api/v1/customer/notification-preferences', ['marketing_email_opt_in' => true]);

        $response->assertOk();
        $this->assertTrue($customer->fresh()->marketing_email_opt_in);
    }
}
