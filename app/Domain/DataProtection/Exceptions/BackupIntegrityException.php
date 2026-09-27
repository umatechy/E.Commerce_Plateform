<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Exceptions;

use RuntimeException;

/** Module 23 Phase 15 "Backup Integrity" — checksum mismatch or unreadable artifact. */
final class BackupIntegrityException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct("Backup integrity check failed: {$reason}");
    }
}
