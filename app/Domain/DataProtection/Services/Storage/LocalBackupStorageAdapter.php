<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Services\Storage;

use App\Domain\DataProtection\Exceptions\BackupIntegrityException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Backup storage on a Laravel filesystem disk (config('backup.disk'),
 * the private `local` disk by default). Any disk the filesystem
 * abstraction supports works here, including an S3-compatible one; only
 * the local disk has been run.
 *
 * Everything is streamed. A database dump is never read into memory.
 * No method returns a URL: artifacts are never public and are never
 * handed to a browser.
 */
final class LocalBackupStorageAdapter implements BackupStorageAdapter
{
    public function store(string $localTempFilePath, string $storagePath): void
    {
        $stream = fopen($localTempFilePath, 'rb') ?: throw new BackupIntegrityException('The backup working file could not be read.');

        try {
            if (! $this->disk()->put($storagePath, $stream)) {
                throw new BackupIntegrityException('Backup storage refused the artifact.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function exists(string $storagePath): bool
    {
        return $this->disk()->exists($storagePath);
    }

    public function size(string $storagePath): int
    {
        return $this->disk()->size($storagePath);
    }

    public function checksum(string $storagePath): string
    {
        $stream = $this->disk()->readStream($storagePath) ?? throw new BackupIntegrityException('Stored artifact could not be read.');

        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    public function retrieveToLocalPath(string $storagePath): string
    {
        $source = $this->disk()->readStream($storagePath) ?? throw new BackupIntegrityException('Stored artifact could not be read.');
        $tempPath = tempnam(sys_get_temp_dir(), 'backup_restore_') ?: throw new BackupIntegrityException('A backup working file could not be created.');
        $target = fopen($tempPath, 'wb') ?: throw new BackupIntegrityException('A backup working file could not be opened.');

        try {
            stream_copy_to_stream($source, $target);
        } finally {
            fclose($source);
            fclose($target);
        }

        return $tempPath;
    }

    public function delete(string $storagePath): void
    {
        if (! $this->disk()->delete($storagePath)) {
            throw new BackupIntegrityException('Backup storage could not delete the artifact.');
        }
    }

    public function diskName(): string
    {
        return (string) config('backup.disk', 'local');
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }
}
