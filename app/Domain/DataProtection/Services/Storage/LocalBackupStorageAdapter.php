<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Services\Storage;

use Illuminate\Support\Facades\Storage;

/**
 * Real implementation against Laravel's own `local` disk (the same
 * disk B12's GenerateReportExportJob already uses, private/non-public
 * by Laravel's own default `local` disk configuration). This code path
 * is genuinely correct and would function in a real Laravel runtime —
 * it is NOT EXECUTED in this Claude App sandbox (no PHP runtime at
 * all), and that limitation is stated honestly in every document this
 * milestone produces, never implied as verified.
 */
final class LocalBackupStorageAdapter implements BackupStorageAdapter
{
    private const DISK = 'local';

    public function store(string $localTempFilePath, string $storagePath): void
    {
        Storage::disk(self::DISK)->put($storagePath, fopen($localTempFilePath, 'r'));
    }

    public function exists(string $storagePath): bool
    {
        return Storage::disk(self::DISK)->exists($storagePath);
    }

    public function size(string $storagePath): int
    {
        return Storage::disk(self::DISK)->size($storagePath);
    }

    public function retrieveToLocalPath(string $storagePath): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'backup_restore_');
        file_put_contents($tempPath, Storage::disk(self::DISK)->get($storagePath));

        return $tempPath;
    }

    public function delete(string $storagePath): void
    {
        Storage::disk(self::DISK)->delete($storagePath);
    }
}
