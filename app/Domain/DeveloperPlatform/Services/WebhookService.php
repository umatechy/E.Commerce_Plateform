<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Services;

use App\Domain\DeveloperPlatform\Exceptions\InvalidWebhookUrlException;
use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\DeveloperPlatform\Models\WebhookSubscription;
use App\Domain\DeveloperPlatform\Models\WebhookSubscriptionStatus;
use Illuminate\Support\Str;

/**
 * Module 31 §58-59 "SSRF / Webhook Security" — Non-Negotiable: never
 * blindly accept an arbitrary server-side-fetchable URL. This is a
 * genuine, buildable, TIME-OF-CHECK protection using PHP's own real
 * IP-validation flags — explicitly documented as NOT a complete
 * defense (DNS rebinding between check-time and actual delivery-time
 * is a named residual risk in the security review), per this
 * milestone's own "do not invent an incomplete SSRF protection system
 * without documenting limitations."
 */
final class WebhookService
{
    /**
     * @param list<string> $subscribedEvents
     * @return array{subscription: WebhookSubscription, plaintextSecret: string}
     *
     * @throws InvalidWebhookUrlException
     */
    public function subscribe(DeveloperApplication $application, string $url, array $subscribedEvents): array
    {
        $this->assertUrlIsSafe($url);

        $secret = Str::random(48);

        $subscription = WebhookSubscription::query()->create([
            'developer_application_id' => $application->id,
            'store_id' => $application->store_id,
            'url' => $url,
            'signing_secret' => $secret,
            'subscribed_events' => array_values(array_unique($subscribedEvents)),
            'status' => WebhookSubscriptionStatus::Active,
        ]);

        return ['subscription' => $subscription, 'plaintextSecret' => $secret];
    }

    public function disable(WebhookSubscription $subscription): WebhookSubscription
    {
        $subscription->update(['status' => WebhookSubscriptionStatus::Disabled]);

        return $subscription->fresh();
    }

    /**
     * @throws InvalidWebhookUrlException
     */
    private function assertUrlIsSafe(string $url): void
    {
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'https') {
            throw new InvalidWebhookUrlException('Webhook URL must be a valid https:// URL.');
        }

        $host = $parts['host'];
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        if ($ip === $host && ! filter_var($host, FILTER_VALIDATE_IP)) {
            throw new InvalidWebhookUrlException('Webhook URL host could not be resolved.');
        }

        $isPublicIp = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

        if (! $isPublicIp) {
            throw new InvalidWebhookUrlException('Webhook URL must not resolve to a private, reserved, or loopback address.');
        }
    }
}
