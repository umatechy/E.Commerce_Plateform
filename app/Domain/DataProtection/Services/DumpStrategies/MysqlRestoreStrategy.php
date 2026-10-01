<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Services\DumpStrategies;

/**
 * Real implementation using the actual `mysql` client binary, with the
 * credentials discipline of MysqlClient. The dump is read by the
 * client's own `source` command, not through a shell redirect.
 *
 * The import path is the one the restore rehearsal runs for real
 * (MysqlRehearsalTarget). A production restore — this class, against the
 * live database — has NOT been executed; see
 * docs/checkpoints/checkpoint-b30.md.
 */
final class MysqlRestoreStrategy implements DatabaseRestoreStrategy
{
    public function __construct(private readonly MysqlClient $client) {}

    public function restore(string $localSqlFilePath): void
    {
        $this->client->run('mysql', [
            $this->client->database(),
            '-e', 'source '.MysqlClient::sourcePath($localSqlFilePath),
        ], 'mysql restore failed');
    }
}
