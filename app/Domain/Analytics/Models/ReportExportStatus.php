<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Models;

/** Module 22 §43 "Report Exports" — a small, purpose-specific lifecycle, not the full Notification delivery state machine (a different concern). */
enum ReportExportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';
}
