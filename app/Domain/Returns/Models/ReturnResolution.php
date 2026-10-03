<?php

declare(strict_types=1);

namespace App\Domain\Returns\Models;

/**
 * Module 09 §45 "Possible resolutions". Store credit (§52) is a future
 * foundation in the blueprint and is not offered.
 */
enum ReturnResolution: string
{
    case Refund = 'refund';
    case Replacement = 'replacement'; // the same items again, at no charge (§54)
    case Exchange = 'exchange'; // other items; the returned value counts towards them (§53)

    public function needsNewOrder(): bool
    {
        return $this !== self::Refund;
    }
}
