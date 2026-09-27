<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\DataProtection\Services\DumpStrategies\DatabaseRestoreStrategy;

final class FakeDatabaseRestoreStrategy implements DatabaseRestoreStrategy
{
    public bool $wasCalled = false;

    public function restore(string $localSqlFilePath): void
    {
        $this->wasCalled = true;
    }
}
