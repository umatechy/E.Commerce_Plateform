<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

/** Module 21 §5 "Message Types" — the module's own list, used verbatim. */
enum NotificationMessageType: string
{
    case Transactional = 'transactional';
    case Marketing = 'marketing';
    case System = 'system';
    case Security = 'security';
    case Administrative = 'administrative';

    /** Module 21 §10: only Marketing is ever subject to consent/suppression checks. */
    public function requiresMarketingConsent(): bool
    {
        return $this === self::Marketing;
    }
}
