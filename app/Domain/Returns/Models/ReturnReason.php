<?php

declare(strict_types=1);

namespace App\Domain\Returns\Models;

/** Module 09 §45 "Reason": a fixed code for reports; the words of the person asking go in `description`. */
enum ReturnReason: string
{
    case Defective = 'defective';
    case DamagedInTransit = 'damaged_in_transit';
    case WrongItem = 'wrong_item';
    case NotAsDescribed = 'not_as_described';
    case SizeOrFit = 'size_or_fit';
    case ChangedMind = 'changed_mind';
    case ArrivedLate = 'arrived_late';
    case Other = 'other';
}
