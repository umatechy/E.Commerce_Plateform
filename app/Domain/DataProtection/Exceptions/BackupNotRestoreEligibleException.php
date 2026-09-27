<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Exceptions;

use RuntimeException;

/** Module 23 Phase 18 "Restore Preflight". */
final class BackupNotRestoreEligibleException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct("This backup cannot be restored: {$reason}");
    }
}
