<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

/**
 * Module 21 §4 "Communication Channels" — the module's own initial-
 * channel list (STOREFRONT/Telegram/Messenger/Voice are explicitly
 * "Future" per the spec, not built). Only Email and InApp have real
 * adapters in B11 — see docs/development/b11-inspection-findings.md.
 */
enum NotificationChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';
    case Push = 'push';
    case InApp = 'in_app';
}
