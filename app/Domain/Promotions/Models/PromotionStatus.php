<?php

declare(strict_types=1);

namespace App\Domain\Promotions\Models;

/**
 * Module 14 §21 "Promotion Status" — the module's own suggested list,
 * used verbatim. Only Draft/Active/Paused/Disabled/Archived are
 * actually distinguished by application logic in B9; Scheduled/Expired
 * are derived on read from starts_at/ends_at rather than stored
 * separately (documented — avoids a scheduled job just to flip a
 * status column when the same information is already in the date
 * columns).
 */
enum PromotionStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Paused = 'paused';
    case Expired = 'expired';
    case Disabled = 'disabled';
    case Archived = 'archived';
}
