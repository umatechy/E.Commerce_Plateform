<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Models;

/** Module 15 §7 "Campaign Status" — the module's own suggested list, used verbatim. */
enum CampaignStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
    case Archived = 'archived';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Failed, self::Archived], true);
    }
}
