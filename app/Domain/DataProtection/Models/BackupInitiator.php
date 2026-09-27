<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Models;

enum BackupInitiator: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';
    case PreRestoreSafety = 'pre_restore_safety'; // Module 23 Phase 19 — the automatic safety snapshot taken immediately before a destructive restore
}
