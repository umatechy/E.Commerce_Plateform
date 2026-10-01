<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Services\DumpStrategies;

use App\Domain\DataProtection\Exceptions\BackupIntegrityException;

/**
 * Real, production-shaped implementation using the actual `mysqldump`
 * binary — --single-transaction for InnoDB consistency without locking
 * the whole database (Module 23 Phase 6: "transactional consistency...
 * active writes... locking implications"), --routines/--triggers/--events
 * for full schema fidelity, --no-tablespaces so the backup account does
 * not need the PROCESS privilege (least privilege). Credentials are
 * handled by MysqlClient.
 *
 * Run against real MySQL 8.0 in Phase B30 (local and CI); see
 * docs/checkpoints/checkpoint-b30.md for what was measured.
 */
final class MysqldumpStrategy implements DatabaseDumpStrategy
{
    public function __construct(private readonly MysqlClient $client) {}

    public function dump(): string
    {
        $outputFile = (tempnam(sys_get_temp_dir(), 'backup_dump_') ?: throw new BackupIntegrityException('A working file could not be created.'));

        try {
            $this->client->run('mysqldump', [
                '--single-transaction',
                '--no-tablespaces',
                '--routines',
                '--triggers',
                '--events',
                '--result-file='.$outputFile,
                $this->client->database(),
            ], 'mysqldump failed');

            $this->assertComplete($outputFile);

            return $outputFile;
        } catch (\Throwable $e) {
            @unlink($outputFile);

            throw $e;
        }
    }

    /**
     * mysqldump writes "-- Dump completed" as its last line. A dump that
     * was cut short (disk full, killed process) can still exit zero on
     * some platforms; without that line it is not a backup.
     */
    private function assertComplete(string $file): void
    {
        $handle = fopen($file, 'rb') ?: throw new BackupIntegrityException('The dump could not be read back.');

        try {
            fseek($handle, -min(512, (int) filesize($file)), SEEK_END);
            $tail = (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        if (! str_contains($tail, '-- Dump completed')) {
            throw new BackupIntegrityException('mysqldump did not finish: the dump has no completion marker.');
        }
    }
}
