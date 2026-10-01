<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Models;

/**
 * Which retention rule a backup falls under (Module 23 §29; owner
 * decision 2026-09-30 §5: daily 30 days, monthly 12 months, plus
 * pre-change backups). The durations live in the settings registry
 * (BackupRetentionPolicy), not here.
 */
enum BackupRetentionTier: string
{
    case Daily = 'daily';
    case Monthly = 'monthly';
    case Manual = 'manual';
    case PreChange = 'pre_change'; // taken automatically before a destructive operation
}
