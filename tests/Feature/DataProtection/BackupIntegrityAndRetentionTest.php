<?php

declare(strict_types=1);

namespace Tests\Feature\DataProtection;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupRestoreJob;
use App\Domain\DataProtection\Models\BackupRetentionTier;
use App\Domain\DataProtection\Models\BackupScope;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\DataProtection\Services\BackupService;
use App\Domain\DataProtection\Services\RestoreRehearsalService;
use App\Domain\DataProtection\Services\RestoreService;
use App\Domain\DataProtection\Services\Storage\BackupStorageAdapter;
use App\Domain\Events\Models\OutboxEvent;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FlakyBackupStorageAdapter;
use Tests\TestCase;

/**
 * Phase B30 (gap G4) — a backup that goes bad in storage is found, and
 * retention deletes only what no recovery needs (Module 23 §15, §29–30).
 */
final class BackupIntegrityAndRetentionTest extends TestCase
{
    use InteractsWithBackups, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups();
    }

    private function events(string $type): int
    {
        return OutboxEvent::query()->withoutTenantScope()->where('event_type', $type)->count();
    }

    // --- Integrity ---

    public function test_an_intact_backup_passes_the_recheck(): void
    {
        $backup = $this->platformBackup(['last_checked_at' => null]);

        $this->artisan('backups:verify --deep')->expectsOutputToContain('intact')->assertSuccessful();

        $this->assertSame(BackupStatus::Verified, $backup->fresh()->status);
        $this->assertNotNull($backup->fresh()->last_checked_at);
        $this->assertSame(0, $this->events('backup.verification_failed'));
    }

    public function test_a_backup_changed_in_storage_is_marked_failed_and_can_no_longer_be_restored(): void
    {
        $backup = $this->platformBackup();
        $this->corrupt($backup);

        $this->artisan('backups:verify')->expectsOutputToContain('FAILED')->assertFailed();

        $backup->refresh();
        $this->assertSame(BackupStatus::Failed, $backup->status);
        $this->assertStringContainsString('Integrity re-check failed', (string) $backup->failure_reason);
        $this->assertFalse($backup->isRestoreEligible());
        $this->assertSame(1, $this->events('backup.verification_failed'));
        $this->assertSame(1, AuditLog::query()->where('action', 'backup.verification_failed')->count());

        // A second run finds nothing left to check and raises nothing new.
        $this->artisan('backups:verify --all')->assertSuccessful();
        $this->assertSame(1, $this->events('backup.verification_failed'));
    }

    public function test_a_backup_whose_file_is_gone_is_marked_failed(): void
    {
        $backup = $this->platformBackup();
        Storage::disk('local')->delete($backup->storage_path);

        $this->assertFalse(app(BackupService::class)->recheck($backup, app(BackupStorageAdapter::class)));

        $this->assertSame(BackupStatus::Failed, $backup->fresh()->status);
        $this->assertStringContainsString('missing', (string) $backup->fresh()->failure_reason);
    }

    public function test_same_size_different_bytes_is_caught_by_the_checksum(): void
    {
        $backup = $this->platformBackup();
        $original = Storage::disk('local')->get($backup->storage_path);
        Storage::disk('local')->put($backup->storage_path, strrev($original)); // same length

        $this->assertFalse(app(BackupService::class)->recheck($backup, app(BackupStorageAdapter::class)));
        $this->assertStringContainsString('checksum', (string) $backup->fresh()->failure_reason);
    }

    public function test_verify_all_checks_store_backups_too(): void
    {
        $good = $this->platformBackup();
        $bad = Backup::factory()->withArtifact()->create(['store_id' => Store::factory()->create()->id]);
        $this->corrupt($bad);

        $this->artisan('backups:verify --all')->assertFailed();

        $this->assertSame(BackupStatus::Verified, $good->fresh()->status);
        $this->assertSame(BackupStatus::Failed, $bad->fresh()->status);
    }

    // --- Retention ---

    public function test_expired_daily_and_monthly_backups_are_deleted_and_current_ones_kept(): void
    {
        $this->platformBackup(); // the newest recovery point
        $expiredDaily = $this->platformBackup(['retention_tier' => BackupRetentionTier::Daily, 'expires_at' => now()->subDay(), 'verified_at' => now()->subDays(31)]);
        $expiredMonthly = $this->platformBackup(['retention_tier' => BackupRetentionTier::Monthly, 'expires_at' => now()->subDay(), 'verified_at' => now()->subMonths(13)]);
        $currentMonthly = $this->platformBackup(['retention_tier' => BackupRetentionTier::Monthly, 'expires_at' => now()->addMonths(6), 'verified_at' => now()->subMonths(6)]);

        $this->artisan('backups:expire')->expectsOutputToContain('Deleted 2 expired backup(s).')->assertSuccessful();

        foreach ([$expiredDaily, $expiredMonthly] as $backup) {
            $this->assertSame(BackupStatus::Deleted, $backup->fresh()->status);
            $this->assertFalse(Storage::disk('local')->exists($backup->storage_path));
        }
        $this->assertSame(BackupStatus::Verified, $currentMonthly->fresh()->status);
        $this->assertTrue(Storage::disk('local')->exists($currentMonthly->storage_path));

        $this->assertSame(2, AuditLog::query()->where('action', 'backup.expired')->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'backup.deleted')->count());

        // Running it again changes nothing and fails nothing.
        $this->artisan('backups:expire')->expectsOutputToContain('Deleted 0 expired backup(s).')->assertSuccessful();
        $this->assertSame(2, AuditLog::query()->where('action', 'backup.deleted')->count());
    }

    public function test_the_last_verified_platform_backup_is_kept_even_when_expired(): void
    {
        $older = $this->platformBackup(['expires_at' => now()->subDays(5), 'verified_at' => now()->subDays(40)]);
        $newest = $this->platformBackup(['expires_at' => now()->subDay(), 'verified_at' => now()->subDays(31)]);

        $this->artisan('backups:expire')->assertSuccessful();

        $this->assertSame(BackupStatus::Deleted, $older->fresh()->status);
        $this->assertSame(BackupStatus::Verified, $newest->fresh()->status);
        $this->assertTrue(Storage::disk('local')->exists($newest->storage_path));
    }

    public function test_a_backup_waiting_for_a_restore_is_not_deleted(): void
    {
        $this->platformBackup();
        $wanted = $this->platformBackup(['expires_at' => now()->subDay(), 'verified_at' => now()->subDays(31)]);
        BackupRestoreJob::query()->create(['backup_id' => $wanted->id, 'status' => 'requested']);

        $this->artisan('backups:expire')->assertSuccessful();

        $this->assertSame(BackupStatus::Verified, $wanted->fresh()->status);
        $this->assertTrue(Storage::disk('local')->exists($wanted->storage_path));
    }

    public function test_nothing_is_deleted_while_a_restore_or_a_rehearsal_is_running(): void
    {
        $this->platformBackup();
        $expired = $this->platformBackup(['expires_at' => now()->subDay(), 'verified_at' => now()->subDays(31)]);
        $inUse = $this->platformBackup(['verified_at' => now()->subDays(2)]);

        $running = BackupRestoreJob::query()->create(['backup_id' => $inUse->id, 'status' => 'running']);
        $this->artisan('backups:expire')->expectsOutputToContain('restore or rehearsal is in progress')->assertSuccessful();
        $this->assertSame(BackupStatus::Verified, $expired->fresh()->status);

        $running->update(['status' => 'completed']);
        $rehearsal = Cache::lock(RestoreRehearsalService::LOCK, 60);
        $rehearsal->get();
        $this->artisan('backups:expire')->expectsOutputToContain('restore or rehearsal is in progress')->assertSuccessful();
        $this->assertSame(BackupStatus::Verified, $expired->fresh()->status);

        $rehearsal->release();
        $this->artisan('backups:expire')->assertSuccessful();
        $this->assertSame(BackupStatus::Deleted, $expired->fresh()->status);
    }

    public function test_one_backup_that_cannot_be_deleted_does_not_stop_the_others_and_is_reported(): void
    {
        $this->platformBackup();
        $stuck = $this->platformBackup(['expires_at' => now()->subDay(), 'verified_at' => now()->subDays(40)]);
        $fine = $this->platformBackup(['expires_at' => now()->subDay(), 'verified_at' => now()->subDays(35)]);

        $storage = new FlakyBackupStorageAdapter();
        $storage->failDeleteOf = [$stuck->storage_path];
        $this->app->instance(BackupStorageAdapter::class, $storage);

        $this->artisan('backups:expire')->assertFailed();

        $this->assertSame(BackupStatus::Deleted, $fine->fresh()->status);
        // Not "deleted" while its file is still there: it is tried again next time.
        $this->assertSame(BackupStatus::Expired, $stuck->fresh()->status);
        $this->assertTrue(Storage::disk('local')->exists($stuck->storage_path));
        $this->assertFalse($stuck->fresh()->isRestoreEligible());

        $this->assertSame(1, $this->events('backup.retention_cleanup_failed'));
        $entry = AuditLog::query()->where('action', 'backup.retention_cleanup_failed')->sole();
        $this->assertSame(1, $entry->contextData()['failed']);
        $this->assertNull($entry->store_id);

        // Storage recovers: the next run finishes the job. Still one event for the day.
        $storage->failDeleteOf = [];
        $this->artisan('backups:expire')->assertSuccessful();
        $this->assertSame(BackupStatus::Deleted, $stuck->fresh()->status);
        $this->assertFalse(Storage::disk('local')->exists($stuck->storage_path));
    }

    public function test_a_second_cleanup_running_at_the_same_time_does_nothing(): void
    {
        $this->platformBackup();
        $expired = $this->platformBackup(['expires_at' => now()->subDay(), 'verified_at' => now()->subDays(31)]);

        $other = Cache::lock('backups:retention', 60);
        $other->get();

        $this->artisan('backups:expire')->expectsOutputToContain('already running elsewhere')->assertSuccessful();
        $this->assertSame(BackupStatus::Verified, $expired->fresh()->status);
    }

    public function test_cleanup_removes_each_stores_expired_backups_and_no_one_elses_current_ones(): void
    {
        $this->platformBackup();
        [$storeA, $storeB] = [Store::factory()->create(), Store::factory()->create()];
        $expiredA = Backup::factory()->withArtifact()->create(['store_id' => $storeA->id, 'expires_at' => now()->subDay()]);
        $currentB = Backup::factory()->withArtifact()->create(['store_id' => $storeB->id]);

        $this->artisan('backups:expire')->assertSuccessful();

        $this->assertSame(BackupStatus::Deleted, $expiredA->fresh()->status);
        $this->assertSame(BackupStatus::Verified, $currentB->fresh()->status);
        $this->assertTrue(Storage::disk('local')->exists($currentB->storage_path));
        // The deletion is recorded in the owning store's audit chain.
        $this->assertSame($storeA->id, AuditLog::query()->where('action', 'backup.deleted')->sole()->store_id);
    }

    public function test_an_expired_failed_backup_is_removed_too(): void
    {
        $this->platformBackup();
        $failed = Backup::factory()->create(['scope' => BackupScope::Platform, 'store_id' => null, 'status' => BackupStatus::Failed, 'storage_path' => null, 'expires_at' => now()->subDay()]);

        $this->artisan('backups:expire')->assertSuccessful();

        $this->assertSame(BackupStatus::Deleted, $failed->fresh()->status);
    }

    public function test_the_production_restore_lock_name_is_shared_with_the_restore_service(): void
    {
        // Retention looks at restore jobs, restores at this lock: both must keep meaning the same thing.
        $this->assertSame('backups:production-restore', RestoreService::LOCK);
        $this->assertSame('backups:rehearsal', RestoreRehearsalService::LOCK);
    }
}
