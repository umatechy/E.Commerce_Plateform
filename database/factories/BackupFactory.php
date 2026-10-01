<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupInitiator;
use App\Domain\DataProtection\Models\BackupScope;
use App\Domain\DataProtection\Models\BackupStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Backup> */
final class BackupFactory extends Factory
{
    protected $model = Backup::class;

    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'scope' => BackupScope::Store,
            'status' => BackupStatus::Verified,
            'initiated_by' => BackupInitiator::Manual,
            'storage_disk' => 'local',
            'storage_path' => 'backups/'.Str::ulid().'.sql',
            'size_bytes' => 1024,
            'checksum_sha256' => hash('sha256', 'test'),
            'verified_at' => now(),
            'expires_at' => now()->addDays(30),
        ];
    }

    /**
     * A backup whose artifact really is in backup storage, with the size
     * and checksum to match — what verification, restore preflight and
     * rehearsal read. Use Storage::fake() for the backup disk first.
     */
    public function withArtifact(string $sql = "-- test dump\nCREATE TABLE t (id INT);\n-- Dump completed\n"): static
    {
        return $this->state(function () use ($sql) {
            $artifact = (string) gzencode($sql);
            $path = 'backups/'.Str::ulid().'.sql.gz';
            \Illuminate\Support\Facades\Storage::disk((string) config('backup.disk', 'local'))->put($path, $artifact);

            return [
                'storage_path' => $path,
                'size_bytes' => strlen($artifact),
                'checksum_sha256' => hash('sha256', $artifact),
                'compression' => 'gzip',
                'is_encrypted' => false,
            ];
        });
    }}
