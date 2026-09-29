<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\Http\Controllers\UnsubscribeController;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B11 — Unsubscribe link security (Module 21 §85-86). No auth
 * required (the whole point), but signature verification is
 * mandatory.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class UnsubscribeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_signature_creates_a_suppression(): void
    {
        $store = Store::factory()->create();
        $signature = UnsubscribeController::signatureFor($store, NotificationChannel::Email, 'jane@example.com');

        $response = $this->getJson('/api/v1/public/notifications/unsubscribe?'.http_build_query([
            'store' => $store->slug, 'channel' => 'email', 'destination' => 'jane@example.com', 'signature' => $signature,
        ]));

        $response->assertOk();
        $this->assertDatabaseHas('notification_suppressions', ['channel' => 'email', 'destination' => 'jane@example.com']);
    }

    public function test_forged_signature_is_rejected(): void
    {
        $store = Store::factory()->create();

        $response = $this->getJson('/api/v1/public/notifications/unsubscribe?'.http_build_query([
            'store' => $store->slug, 'channel' => 'email', 'destination' => 'jane@example.com', 'signature' => 'forged-signature',
        ]));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('notification_suppressions', ['destination' => 'jane@example.com']);
    }

    public function test_a_signature_from_one_store_does_not_work_for_another_store(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $signatureForA = UnsubscribeController::signatureFor($storeA, NotificationChannel::Email, 'jane@example.com');

        $response = $this->getJson('/api/v1/public/notifications/unsubscribe?'.http_build_query([
            'store' => $storeB->slug, 'channel' => 'email', 'destination' => 'jane@example.com', 'signature' => $signatureForA,
        ]));

        $response->assertStatus(422);
    }

    public function test_unsubscribe_requires_no_authentication(): void
    {
        $store = Store::factory()->create();
        $signature = UnsubscribeController::signatureFor($store, NotificationChannel::Email, 'jane@example.com');

        $response = $this->getJson('/api/v1/public/notifications/unsubscribe?'.http_build_query([
            'store' => $store->slug, 'channel' => 'email', 'destination' => 'jane@example.com', 'signature' => $signature,
        ]));

        $this->assertNotSame(401, $response->status());
    }

    public function test_repeated_unsubscribe_of_the_same_destination_is_idempotent(): void
    {
        $store = Store::factory()->create();
        $signature = UnsubscribeController::signatureFor($store, NotificationChannel::Email, 'jane@example.com');
        $url = '/api/v1/public/notifications/unsubscribe?'.http_build_query([
            'store' => $store->slug, 'channel' => 'email', 'destination' => 'jane@example.com', 'signature' => $signature,
        ]);

        $this->getJson($url)->assertOk();
        $this->getJson($url)->assertOk();

        $this->assertSame(1, \App\Domain\Notifications\Models\NotificationSuppression::query()->withoutTenantScope()->where('destination', 'jane@example.com')->count());
    }
}
