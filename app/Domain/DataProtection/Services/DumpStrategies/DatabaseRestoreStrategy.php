<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Services\DumpStrategies;

/** Module 23 Phase 16 "Restore Architecture" — the counterpart to DatabaseDumpStrategy. */
interface DatabaseRestoreStrategy
{
    /** Imports the given local SQL file into the current database connection. */
    public function restore(string $localSqlFilePath): void;
}
