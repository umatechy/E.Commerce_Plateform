<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\DataProtection\Services\DumpStrategies\DatabaseDumpStrategy;

final class FailingDatabaseDumpStrategy implements DatabaseDumpStrategy
{
    public function dump(): string
    {
        throw new \RuntimeException('simulated mysqldump failure');
    }
}
