<?php

declare(strict_types=1);

namespace App\Domain\Customers\Models;

/**
 * Module 10 §29: a customer's standing with the store.
 *
 * - Active: normal.
 * - Blocked (§30–31): cannot sign in or place orders; history kept.
 * - Archived (§58): no sign-in, no marketing; history kept; restorable.
 */
enum CustomerStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
    case Archived = 'archived';

    public function maySignIn(): bool
    {
        return $this === self::Active;
    }

    public function mayOrder(): bool
    {
        return $this === self::Active;
    }

    public function mayReceiveMarketing(): bool
    {
        return $this === self::Active;
    }
}
