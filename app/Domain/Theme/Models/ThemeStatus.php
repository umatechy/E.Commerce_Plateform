<?php

declare(strict_types=1);

namespace App\Domain\Theme\Models;

enum ThemeStatus: string
{
    case Active = 'active';
    case Deprecated = 'deprecated';
}
