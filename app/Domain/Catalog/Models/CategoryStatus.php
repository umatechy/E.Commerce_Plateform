<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

/** Module 07 §10 "Category Status" — the module's own suggested list. */
enum CategoryStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Hidden = 'hidden';
    case Scheduled = 'scheduled';
    case Archived = 'archived';
}
