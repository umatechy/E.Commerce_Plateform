<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Models;

enum ApiKeyStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
