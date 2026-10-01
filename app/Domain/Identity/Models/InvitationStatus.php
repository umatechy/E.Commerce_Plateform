<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

/** Module 02 §18 — an invitation is single-use: once it leaves Pending it never returns. */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
