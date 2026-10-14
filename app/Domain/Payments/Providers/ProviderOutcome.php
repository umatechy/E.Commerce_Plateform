<?php

declare(strict_types=1);

namespace App\Domain\Payments\Providers;

/**
 * What a provider call established. Unknown is its own outcome: a timeout or
 * an unreadable answer must never be taken as a failure, or a shopper who
 * did pay would be asked to pay again (Module 12 §61–62).
 */
enum ProviderOutcome: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Pending = 'pending';
    case Unknown = 'unknown';
}
