<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Console;

use Illuminate\Console\Command;

/**
 * Prints a new backup encryption key (Module 23 §14). It changes
 * nothing: the operator puts the value into the environment as
 * BACKUP_ENCRYPTION_KEY and keeps a copy somewhere that is NOT the
 * backup storage. Backups made with a key can only be read with it.
 */
final class GenerateBackupKeyCommand extends Command
{
    protected $signature = 'backups:generate-key';

    protected $description = 'Print a new backup encryption key for BACKUP_ENCRYPTION_KEY.';

    public function handle(): int
    {
        $this->line(base64_encode(random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES)));
        $this->warn('Set it as BACKUP_ENCRYPTION_KEY. Keep a copy away from the backups: without it they cannot be restored.');

        return self::SUCCESS;
    }
}
