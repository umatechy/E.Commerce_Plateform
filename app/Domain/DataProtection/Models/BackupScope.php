<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Models;

enum BackupScope: string
{
    case Platform = 'platform';
    case Store = 'store';
}
