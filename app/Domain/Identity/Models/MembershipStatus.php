<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

/**
 * Store membership states (Module 02 §37). Only Active grants access
 * (StoreSwitcher). Suspended keeps the seat and can be reactivated;
 * Revoked means removed from the team. The row is kept for history and
 * reused if the person is invited again.
 */
enum MembershipStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Revoked = 'revoked';
}
