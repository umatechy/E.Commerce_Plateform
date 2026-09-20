<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Models\NotificationChannel;

/** The ONE place a NotificationChannel enum value maps to a concrete adapter (mirrors Phase B7/B8's GatewayResolver/CarrierResolver exactly). */
final class NotificationChannelResolver
{
    public function resolve(NotificationChannel $channel): NotificationChannelContract
    {
        return match ($channel) {
            NotificationChannel::Email => new EmailChannel(),
            NotificationChannel::InApp => new InAppChannel(),
            NotificationChannel::Sms => new UnconfiguredChannel('stub_sms'),
            NotificationChannel::WhatsApp => new UnconfiguredChannel('stub_whatsapp'),
            NotificationChannel::Push => new UnconfiguredChannel('stub_push'),
        };
    }
}
