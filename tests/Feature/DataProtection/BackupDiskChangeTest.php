<?php

declare(strict_types=1);

namespace Tests\Feature\DataProtection;

use App\Domain\DataProtection\Jobs\RunBackupJob;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\DataProtection\Services\BackupService;
use App\Domain\DataProtection\Services\DumpStrategies\DatabaseDumpStrategy;
use App\Domain\DataProtection\Services\Storage\BackupStorageAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Deployment A9: moving backups off the server (BACKUP_DISK local → an
 * S3-compatible disk) must not strand the backups made before — each is
 * read, checked, restored from and removed where it was stored — while
 * every new backup goes to the new location.
 */
final class BackupDiskChangeTest extends TestCase
{
    use InteractsWithBackups, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups();
        Storage::fake('offsite');
        config(['backup.encryption_key' => base64_encode(random_bytes(32))]);
    }

    public function test_backups_made_before_the_change_stay_usable_and_new_ones_go_to_the_new_disk(): void
    {
        $old = $this->platformBackup();
        $this->assertSame('local', $old->storage_disk);

        config(['backup.disk' => 'offsite']);
        $storage = app(BackupStorageAdapter::class);

        // Still checked where it was stored — not reported missing on the new disk.
        $this->artisan('backups:verify --deep')->expectsOutputToContain('intact')->assertSuccessful();
        $this->assertSame(BackupStatus::Verified, $old->fresh()->status);
        $this->assertTrue(app(BackupService::class)->recheck($old->fresh(), $storage, deep: true));

        // A restore can still read it.
        $local = $storage->forBackup($old)->retrieveToLocalPath($old->storage_path);
        $this->assertFileExists($local);
        @unlink($local);

        // A new backup is stored off-server only.
        Queue::fake();
        $new = app(BackupService::class)->requestScheduled(now()->addDay())['backup'];
        (new RunBackupJob($new->id))->handle(app(BackupService::class), app(DatabaseDumpStrategy::class), $storage);
        $new->refresh();
        $this->assertSame(BackupStatus::Verified, $new->status);
        $this->assertSame('offsite', $new->storage_disk);
        Storage::disk('offsite')->assertExists($new->storage_path);
        Storage::disk('local')->assertMissing($new->storage_path);

        // Expiring the old one removes its file from the disk it is on.
        $old->update(['status' => BackupStatus::Expired, 'expires_at' => now()->subDay()]);
        $this->artisan('backups:expire')->assertSuccessful();
        Storage::disk('local')->assertMissing($old->storage_path);
        $this->assertSame(BackupStatus::Deleted, $old->fresh()->status);
        Storage::disk('offsite')->assertExists($new->storage_path);
    }

    public function test_a_backup_without_a_recorded_disk_is_looked_for_on_the_current_one(): void
    {
        $backup = $this->platformBackup(['storage_disk' => null]);

        $this->assertSame('local', app(BackupStorageAdapter::class)->forBackup($backup)->diskName());
    }
}
