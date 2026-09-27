<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\DataProtection\Services\DumpStrategies\DatabaseDumpStrategy;

/** Test double — avoids requiring a real `mysqldump` binary/MySQL server, which this Claude App sandbox does not have. */
final class FakeDatabaseDumpStrategy implements DatabaseDumpStrategy
{
    public function dump(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fake_dump_');
        file_put_contents($path, "-- fake sql dump for test purposes\n");

        return $path;
    }
}
