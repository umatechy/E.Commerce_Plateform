<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Models;

enum WebhookSubscriptionStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
