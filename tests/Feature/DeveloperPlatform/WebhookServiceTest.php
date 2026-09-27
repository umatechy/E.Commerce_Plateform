<?php

declare(strict_types=1);

namespace Tests\Feature\DeveloperPlatform;

use App\Domain\DeveloperPlatform\Exceptions\InvalidWebhookUrlException;
use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\DeveloperPlatform\Services\WebhookService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase B18 — Webhook SSRF protection: real, IP-literal checks that
 * need no DNS resolution to exercise (Module 31 §58-59, Non-
 * Negotiable). DNS-hostname-based cases are NOT exercised here (would
 * require live DNS resolution, unavailable in this sandbox) — see
 * docs/security/b18-security-review.md for the honestly-documented
 * scope of this test.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class WebhookServiceTest extends TestCase
{
    use RefreshDatabase;

    private function application(): DeveloperApplication
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        return DeveloperApplication::factory()->for($store)->create();
    }

    public function test_a_loopback_ip_literal_url_is_rejected(): void
    {
        $this->expectException(InvalidWebhookUrlException::class);
        app(WebhookService::class)->subscribe($this->application(), 'https://127.0.0.1/webhook', ['order.created']);
    }

    public function test_a_private_ip_literal_url_is_rejected(): void
    {
        $this->expectException(InvalidWebhookUrlException::class);
        app(WebhookService::class)->subscribe($this->application(), 'https://192.168.1.1/webhook', ['order.created']);
    }

    public function test_a_non_https_url_is_rejected(): void
    {
        $this->expectException(InvalidWebhookUrlException::class);
        app(WebhookService::class)->subscribe($this->application(), 'http://example.com/webhook', ['order.created']);
    }

    public function test_a_public_ip_literal_url_is_accepted(): void
    {
        $result = app(WebhookService::class)->subscribe($this->application(), 'https://93.184.216.34/webhook', ['order.created']);

        $this->assertSame('active', $result['subscription']->status->value);
    }

    public function test_the_signing_secret_is_returned_exactly_once_and_never_stored_in_plaintext_view(): void
    {
        $result = app(WebhookService::class)->subscribe($this->application(), 'https://93.184.216.34/webhook', ['order.created']);

        $this->assertNotEmpty($result['plaintextSecret']);
        $this->assertArrayNotHasKey('signing_secret', $result['subscription']->toArray()); // $hidden
    }

    public function test_disabling_a_subscription_stops_it_from_matching_future_events(): void
    {
        $result = app(WebhookService::class)->subscribe($this->application(), 'https://93.184.216.34/webhook', ['order.created']);

        $disabled = app(WebhookService::class)->disable($result['subscription']);

        $this->assertFalse($disabled->isSubscribedTo('order.created'));
    }
}
