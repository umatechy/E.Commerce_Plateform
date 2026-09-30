<?php

declare(strict_types=1);

namespace App\Domain\Support\Models;

enum SupportDesk: string
{
    /** A store's shoppers ↔ that store's staff. */
    case Store = 'store';
    /** A store's team ↔ the platform's support staff. */
    case Platform = 'platform';

    public function prefix(): string
    {
        return $this === self::Store ? 'S-' : 'P-';
    }
}
