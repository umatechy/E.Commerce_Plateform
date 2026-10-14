<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Messaging;

use App\Domain\Settings\Services\ConfigService;

/**
 * The one list of SMS and WhatsApp providers (Module 21 §31; Module 30 §27).
 * Meta's Cloud API is the owner's WhatsApp choice; Twilio's production use
 * waits for Umar Techy's go-ahead (`messaging.live_providers`, after the
 * sender-ID, delivery and pricing checks of owner decision 3). The test
 * messengers exist only where config('messaging.simulator') is on and never
 * in production.
 */
final class MessagingRegistry
{
    /** @var list<string> providers that need the platform's go-ahead for production */
    public const GATED = ['twilio'];

    public function __construct(private readonly ConfigService $config) {}

    /** @return array<string, MessagingAdapter> */
    public function all(): array
    {
        $adapters = ['meta_whatsapp' => new MetaWhatsAppAdapter(), 'twilio' => new TwilioSmsAdapter()];
        if (self::simulatorEnabled()) {
            $adapters['test_whatsapp'] = new TestMessengerAdapter('whatsapp');
            $adapters['test_sms'] = new TestMessengerAdapter('sms');
        }

        return $adapters;
    }

    /** @return array<string, MessagingAdapter> */
    public function forChannel(string $channel): array
    {
        return array_filter($this->all(), fn (MessagingAdapter $a) => $a->capabilities()->channel === $channel);
    }

    public function find(string $key): ?MessagingAdapter
    {
        return $this->all()[$key] ?? null;
    }

    public function get(string $key): MessagingAdapter
    {
        return $this->find($key) ?? throw new \InvalidArgumentException("Unknown messaging provider: {$key}");
    }

    public static function simulatorEnabled(): bool
    {
        return (bool) config('messaging.simulator') && ! app()->isProduction();
    }

    public function isTest(string $key): bool
    {
        return str_starts_with($key, 'test_');
    }

    /** May production accounts of this provider send real messages? */
    public function liveAllowed(string $key): bool
    {
        if ($this->isTest($key)) {
            return false;
        }

        return ! in_array($key, self::GATED, true) || in_array($key, (array) $this->config->get('messaging.live_providers'), true);
    }
}
