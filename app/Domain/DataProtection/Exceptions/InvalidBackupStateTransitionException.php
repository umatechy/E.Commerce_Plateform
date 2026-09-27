<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Exceptions;

use RuntimeException;

final class InvalidBackupStateTransitionException extends RuntimeException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct("Cannot transition a backup from \"{$from}\" to \"{$to}\".");
    }
}
