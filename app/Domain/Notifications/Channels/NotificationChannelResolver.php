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
            // Phase B50 (gap G10): the store's (or Umar Techy's) own SMS and WhatsApp accounts.
            NotificationChannel::Sms => new MessagingChannel(NotificationChannel::Sms),
            NotificationChannel::WhatsApp => new MessagingChannel(NotificationChannel::WhatsApp),
            NotificationChannel::Push => new UnconfiguredChannel('stub_push'),
        };
    }
}
