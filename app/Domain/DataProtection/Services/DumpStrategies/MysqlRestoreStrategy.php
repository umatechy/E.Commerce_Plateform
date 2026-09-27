<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Services\DumpStrategies;

use App\Domain\DataProtection\Exceptions\BackupIntegrityException;
use Illuminate\Support\Facades\Process;

/**
 * Real implementation using the actual `mysql` client binary — same
 * credentials-file discipline as MysqldumpStrategy (never a plaintext
 * -p<password> command-line argument).
 *
 * NOT EXECUTED — ENVIRONMENT LIMITATION: this Claude App sandbox has no
 * MySQL server and, most likely, no `mysql` client binary at all — see
 * docs/checkpoints/checkpoint-b19.md's Future Verification Checklist.
 */
final class MysqlRestoreStrategy implements DatabaseRestoreStrategy
{
    public function restore(string $localSqlFilePath): void
    {
        $config = config('database.connections.'.config('database.default'));
        $credentialsFile = tempnam(sys_get_temp_dir(), 'mysql_restore_cnf_');

        file_put_contents($credentialsFile, sprintf(
            "[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n",
            $config['username'], $config['password'], $config['host'], $config['port'] ?? 3306,
        ));
        chmod($credentialsFile, 0600);

        try {
            $result = Process::run("mysql --defaults-extra-file={$credentialsFile} {$config['database']} < {$localSqlFilePath}");

            if (! $result->successful()) {
                throw new BackupIntegrityException('mysql restore exited with a non-zero status: '.$result->errorOutput());
            }
        } finally {
            @unlink($credentialsFile);
        }
    }
}
