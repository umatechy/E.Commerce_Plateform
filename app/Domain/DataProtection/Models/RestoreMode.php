<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Models;

/** Module 23 §34 "Restore Modes" — the two this platform has. */
enum RestoreMode: string
{
    /** Replaces the live database. Super Admin only, with step-up, confirmation and a safety backup. */
    case Production = 'production';

    /** Restores into a throw-away database, checks it and drops it. Never touches live data. */
    case Rehearsal = 'rehearsal';
}
