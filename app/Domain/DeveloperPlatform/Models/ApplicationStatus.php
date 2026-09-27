<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Models;

enum ApplicationStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Revoked = 'revoked';
}
