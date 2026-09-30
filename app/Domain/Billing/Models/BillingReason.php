<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

enum BillingReason: string
{
    /** The first paid period, after the trial or a reactivation. */
    case SubscriptionStart = 'subscription_start';
    /** A regular renewal. */
    case SubscriptionCycle = 'subscription_cycle';
}
