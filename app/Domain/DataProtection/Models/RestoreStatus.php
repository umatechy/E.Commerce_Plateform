<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Models;

/** Module 23 §16-22 "Restore Architecture" — mirrors this milestone's own explicit lifecycle language. */
enum RestoreStatus: string
{
    case Requested = 'requested';
    case PreflightFailed = 'preflight_failed';
    case Running = 'running';
    case Failed = 'failed';
    case Verified = 'verified';
    case Completed = 'completed';
}
