<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Models;

/**
 * Module 23 §17 "Backup States" — a practical subset of the
 * specification's own "suggested" (not mandatory) 14-state list; see
 * docs/development/b19-inspection-findings.md "Architectural Decision
 * — Backup States" for the two deliberate omissions.
 */
enum BackupStatus: string
{
    case Created = 'created';
    case Queued = 'queued';
    case Running = 'running';
    case Verifying = 'verifying';
    case Verified = 'verified';
    case Failed = 'failed';
    case Expired = 'expired';
    case Deleted = 'deleted';
    case Restoring = 'restoring';
    case Restored = 'restored';
    case RestoreFailed = 'restore_failed';
    case Cancelled = 'cancelled';
}
