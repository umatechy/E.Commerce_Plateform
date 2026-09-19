<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

/** Module 08 §13 "Warehouse Status" — the module's own suggested list. */
enum WarehouseStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';
}
