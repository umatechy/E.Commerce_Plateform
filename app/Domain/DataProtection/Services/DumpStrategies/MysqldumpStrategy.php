<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Services\DumpStrategies;

use App\Domain\DataProtection\Exceptions\BackupIntegrityException;
use Illuminate\Support\Facades\Process;

/**
 * Real, production-shaped implementation using the actual `mysqldump`
 * binary via Laravel's Process facade — --single-transaction for
 * InnoDB consistency without locking the whole database (Module 23
 * Phase 6: "transactional consistency... active writes... locking
 * implications"), --routines/--triggers/--events for full schema
 * fidelity. Credentials are passed via a temporary --defaults-extra-file
 * (never as a plain command-line argument, which would be visible in
 * the process list to any other user on the same machine — a genuine
 * credential-leakage vector `mysqldump -u -p<password>` has).
 *
 * NOT EXECUTED — ENVIRONMENT LIMITATION: this Claude App sandbox has no
 * MySQL server and, most likely, no `mysqldump` binary at all. This
 * code is real and correct for a genuine MySQL 8.0+ deployment but has
 * never been run here — see docs/checkpoints/checkpoint-b19.md's
 * Future Verification Checklist.
 */
final class MysqldumpStrategy implements DatabaseDumpStrategy
{
    public function dump(): string
    {
        $config = config('database.connections.'.config('database.default'));
        $credentialsFile = tempnam(sys_get_temp_dir(), 'mysqldump_cnf_');
        $outputFile = tempnam(sys_get_temp_dir(), 'backup_dump_').'.sql';

        file_put_contents($credentialsFile, sprintf(
            "[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n",
            $config['username'], $config['password'], $config['host'], $config['port'] ?? 3306,
        ));
        chmod($credentialsFile, 0600);

        try {
            $result = Process::run([
                'mysqldump',
                '--defaults-extra-file='.$credentialsFile,
                '--single-transaction',
                '--routines',
                '--triggers',
                '--events',
                '--result-file='.$outputFile,
                $config['database'],
            ]);

            if (! $result->successful()) {
                throw new BackupIntegrityException('mysqldump exited with a non-zero status: '.$result->errorOutput());
            }

            return $outputFile;
        } finally {
            // The credentials file must never survive this call, success or failure.
            @unlink($credentialsFile);
        }
    }
}
