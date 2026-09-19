<?php

declare(strict_types=1);

namespace App\Domain\Packages\Models;

enum EntitlementType: string
{
    case Feature = 'feature';       // boolean on/off (e.g. "multi_currency")
    case UsageLimit = 'usage_limit'; // numeric ceiling (e.g. "max_products")
}
