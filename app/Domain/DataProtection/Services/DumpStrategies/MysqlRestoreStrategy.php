<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Services\DumpStrategies;

/**
 * Real implementation using the actual `mysql` client binary, with the
 * credentials discipline of MysqlClient. The dump reaches the client on
 * its standard input, opened by PHP, not through a shell redirect.
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
        $this->client->import($this->client->database(), $localSqlFilePath, 'mysql restore failed');
    }
}
