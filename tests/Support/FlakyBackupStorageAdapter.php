<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\DataProtection\Services\Storage\BackupStorageAdapter;
use App\Domain\DataProtection\Services\Storage\LocalBackupStorageAdapter;

/** Real storage (the faked disk) that can be told to fail a write or a delete, as an unreachable or full storage does. */
final class FlakyBackupStorageAdapter implements BackupStorageAdapter
{
    public bool $failStore = false;

    /** Storage paths whose deletion fails. */
    public array $failDeleteOf = [];

    public function __construct(private readonly LocalBackupStorageAdapter $real = new LocalBackupStorageAdapter()) {}

    public function store(string $localTempFilePath, string $storagePath): void
    {
        if ($this->failStore) {
            throw new \RuntimeException('simulated storage outage: connection to backup storage timed out');
        }

        $this->real->store($localTempFilePath, $storagePath);
    }

    public function exists(string $storagePath): bool
    {
        return $this->real->exists($storagePath);
    }

    public function size(string $storagePath): int
    {
        return $this->real->size($storagePath);
    }

    public function checksum(string $storagePath): string
    {
        return $this->real->checksum($storagePath);
    }

    public function retrieveToLocalPath(string $storagePath): string
    {
        return $this->real->retrieveToLocalPath($storagePath);
    }

    public function delete(string $storagePath): void
    {
        if (in_array($storagePath, $this->failDeleteOf, true)) {
            throw new \RuntimeException('simulated storage failure: permission denied');
        }

        $this->real->delete($storagePath);
    }

    public function diskName(): string
    {
        return $this->real->diskName();
    }
}
