<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Services\Storage;

/**
 * Module 23 Phase 4/10 "Backup Architecture / Backup Storage" —
 * provider-neutral abstraction. Business logic (BackupService,
 * RestoreService) depends ONLY on this interface, never on a concrete
 * disk/provider — a future S3-compatible adapter can be swapped in via
 * the service container without touching BackupService at all. Every
 * path this interface returns/accepts is an OPAQUE, server-generated
 * identifier — never a client-supplied path (Non-Negotiable, Phase 8).
 */
interface BackupStorageAdapter
{
    /** Writes the given local temp file's contents to backup storage and returns the opaque, server-generated storage path. */
    public function store(string $localTempFilePath, string $storagePath): void;

    public function exists(string $storagePath): bool;

    public function size(string $storagePath): int;

    /** Streams the artifact to a local temp path for restore/verification — never returns a public URL. */
    public function retrieveToLocalPath(string $storagePath): string;

    public function delete(string $storagePath): void;
}
