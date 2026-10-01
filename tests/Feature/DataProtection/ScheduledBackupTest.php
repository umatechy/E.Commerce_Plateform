<?php

declare(strict_types=1);

namespace Tests\Feature\DataProtection;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\DataProtection\Jobs\RunBackupJob;
use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupInitiator;
use App\Domain\DataProtection\Models\BackupRetentionTier;
use App\Domain\DataProtection\Models\BackupScope;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\DataProtection\Services\BackupService;
use App\Domain\DataProtection\Services\DumpStrategies\DatabaseDumpStrategy;
use App\Domain\DataProtection\Services\Storage\BackupStorageAdapter;
use App\Domain\Events\Models\OutboxEvent;
use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FailingDatabaseDumpStrategy;
use Tests\Support\FakeDatabaseDumpStrategy;
use Tests\Support\FlakyBackupStorageAdapter;
use Tests\TestCase;

/**
 * Phase B30 (gap G4) — scheduled backups: created once per day, kept by
 * tier, verified from storage, and honest about failure (Module 23 §9,
 * §15, §18, §29; SRS BKP-001, BKP-003).
 */
final class ScheduledBackupTest extends TestCase
{
    use InteractsWithBackups, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups();
    }

    public function test_the_scheduled_command_makes_one_verified_daily_backup_kept_30_days(): void
    {
        Carbon::setTestNow('2026-10-14 02:00:00');

        $this->artisan('backups:run-scheduled')->assertSuccessful();

        $backup = Backup::query()->sole();
        $this->assertSame(BackupStatus::Verified, $backup->status);
        $this->assertSame(BackupScope::Platform, $backup->scope);
        $this->assertNull($backup->store_id);
        $this->assertSame(BackupInitiator::Scheduled, $backup->initiated_by);
        $this->assertSame(BackupRetentionTier::Daily, $backup->retention_tier);
        $this->assertSame('platform:daily:2026-10-14', $backup->schedule_key);
        $this->assertSame('2026-11-13', $backup->expires_at->toDateString());

        // Metadata (Module 23 §16): what it is, never where the secrets are.
        $this->assertSame('gzip', $backup->compression);
        $this->assertSame(64, strlen((string) $backup->checksum_sha256));
        $this->assertGreaterThan(0, $backup->size_bytes);
        $this->assertNotNull($backup->started_at);
        $this->assertNotNull($backup->completed_at);
        $this->assertNotNull($backup->verified_at);
        $this->assertNotNull($backup->last_checked_at);
        $this->assertStringStartsWith('backup-run-', (string) $backup->request_id);
        $this->assertSame('database', $backup->manifest['backup_type']);
        $this->assertArrayHasKey('duration_ms', $backup->manifest);

        // The stored artifact is what the checksum describes.
        $this->assertSame($backup->checksum_sha256, hash('sha256', Storage::disk('local')->get($backup->storage_path)));
        $this->assertSame("-- fake sql dump for test purposes\n", gzdecode(Storage::disk('local')->get($backup->storage_path)));

        foreach (['backup.created', 'backup.completed', 'backup.verified'] as $action) {
            $entry = AuditLog::query()->where('action', $action)->sole();
            $this->assertSame($backup->request_id, $entry->request_id, "{$action} is not correlated with the run");
        }
    }

    public function test_the_first_of_the_month_is_the_monthly_backup_kept_12_months(): void
    {
        Carbon::setTestNow('2026-11-01 02:00:00');

        $this->artisan('backups:run-scheduled')->assertSuccessful();

        $backup = Backup::query()->sole();
        $this->assertSame(BackupRetentionTier::Monthly, $backup->retention_tier);
        $this->assertSame('platform:monthly:2026-11', $backup->schedule_key);
        $this->assertSame('2027-11-01', $backup->expires_at->toDateString());
    }

    public function test_retention_lengths_come_from_the_settings(): void
    {
        app(TenantContext::class)->resolveToPlatform();
        app(ConfigService::class)->set('backup.retention_days', 7, SettingScope::Platform, null);
        app(ConfigService::class)->set('backup.monthly_retention_months', 3, SettingScope::Platform, null);

        Carbon::setTestNow('2026-10-14 02:00:00');
        $this->assertSame('2026-10-21', app(BackupService::class)->requestScheduled(now())['backup']->expires_at->toDateString());

        Carbon::setTestNow('2026-11-01 02:00:00');
        $this->assertSame('2027-02-01', app(BackupService::class)->requestScheduled(now())['backup']->expires_at->toDateString());
    }

    public function test_running_the_schedule_twice_never_makes_a_second_backup(): void
    {
        Carbon::setTestNow('2026-10-14 02:00:00');

        $this->artisan('backups:run-scheduled')->assertSuccessful();
        $this->artisan('backups:run-scheduled')->expectsOutputToContain("Today's backup already exists")->assertSuccessful();
        $this->assertFalse(app(BackupService::class)->requestScheduled(now())['created']);

        $this->assertSame(1, Backup::query()->count());
        $this->assertCount(1, Storage::disk('local')->files('backups'));

        // The next day is a new backup.
        Carbon::setTestNow('2026-10-15 02:00:00');
        $this->artisan('backups:run-scheduled')->assertSuccessful();
        $this->assertSame(2, Backup::query()->count());
    }

    public function test_the_database_refuses_a_second_backup_for_the_same_day(): void
    {
        Backup::factory()->create(['scope' => BackupScope::Platform, 'store_id' => null, 'schedule_key' => 'platform:daily:2026-10-14']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        Backup::factory()->create(['scope' => BackupScope::Platform, 'store_id' => null, 'schedule_key' => 'platform:daily:2026-10-14']);
    }

    public function test_a_retried_job_does_not_run_the_backup_again(): void
    {
        $backup = app(BackupService::class)->requestScheduled(now())['backup'];
        $path = $backup->fresh()->storage_path;
        $written = Storage::disk('local')->lastModified($path);

        // The queue delivers the same job again (a worker restart, a retry).
        (new RunBackupJob($backup->id))->handle(app(BackupService::class), app(DatabaseDumpStrategy::class), app(BackupStorageAdapter::class));

        $this->assertSame(BackupStatus::Verified, $backup->fresh()->status);
        $this->assertSame($path, $backup->fresh()->storage_path);
        $this->assertSame($written, Storage::disk('local')->lastModified($path));
        $this->assertSame(1, AuditLog::query()->where('action', 'backup.completed')->count());
    }

    public function test_nothing_is_requested_while_automated_backups_are_switched_off(): void
    {
        app(TenantContext::class)->resolveToPlatform();
        app(ConfigService::class)->set('backup.automated_backups_enabled', false, SettingScope::Platform, null);

        $this->artisan('backups:run-scheduled')->expectsOutputToContain('switched off')->assertSuccessful();

        $this->assertSame(0, Backup::query()->count());
    }

    public function test_a_failed_dump_is_recorded_as_failed_and_never_as_a_backup(): void
    {
        $this->app->bind(DatabaseDumpStrategy::class, FailingDatabaseDumpStrategy::class);

        try {
            $this->artisan('backups:run-scheduled');
        } catch (\Throwable) {
            // The job rethrows so the queue can apply its retry policy.
        }

        $backup = Backup::query()->sole();
        $this->assertSame(BackupStatus::Failed, $backup->status);
        $this->assertNull($backup->verified_at);
        $this->assertNull($backup->storage_path);
        $this->assertStringContainsString('simulated mysqldump failure', (string) $backup->failure_reason);
        $this->assertSame([], Storage::disk('local')->files('backups'));

        $event = OutboxEvent::query()->withoutTenantScope()->where('event_type', 'backup.failed')->sole();
        $this->assertSame('dump', $event->payload['stage']);
        $this->assertSame('scheduled', $event->payload['trigger']);
        $this->assertSame(1, AuditLog::query()->where('action', 'backup.failed')->count());
        $this->assertFalse($backup->isRestoreEligible());
    }

    public function test_a_storage_outage_fails_the_backup_at_the_store_stage(): void
    {
        $storage = new FlakyBackupStorageAdapter();
        $storage->failStore = true;
        $this->app->instance(BackupStorageAdapter::class, $storage);

        try {
            app(BackupService::class)->requestScheduled(now());
        } catch (\Throwable) {
        }

        $backup = Backup::query()->sole();
        $this->assertSame(BackupStatus::Failed, $backup->status);
        $this->assertSame('store', OutboxEvent::query()->withoutTenantScope()->where('event_type', 'backup.failed')->sole()->payload['stage']);
        $this->assertSame([], Storage::disk('local')->files('backups'));
    }

    public function test_an_upload_that_does_not_match_its_checksum_is_not_verified(): void
    {
        // Storage that silently stores something else than it was given.
        $storage = new class extends \stdClass implements BackupStorageAdapter
        {
            private \App\Domain\DataProtection\Services\Storage\LocalBackupStorageAdapter $real;

            public function __construct()
            {
                $this->real = new \App\Domain\DataProtection\Services\Storage\LocalBackupStorageAdapter();
            }

            public function store(string $localTempFilePath, string $storagePath): void
            {
                $damaged = (string) file_get_contents($localTempFilePath);
                $damaged[strlen($damaged) - 1] = $damaged[strlen($damaged) - 1] === 'x' ? 'y' : 'x';
                Storage::disk('local')->put($storagePath, $damaged);
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
                $this->real->delete($storagePath);
            }

            public function diskName(): string
            {
                return 'local';
            }
        };
        $this->app->instance(BackupStorageAdapter::class, $storage);

        try {
            app(BackupService::class)->requestScheduled(now());
        } catch (\Throwable) {
        }

        $backup = Backup::query()->sole();
        $this->assertSame(BackupStatus::Failed, $backup->status);
        $this->assertNull($backup->verified_at);
        $this->assertStringContainsString('checksum', (string) $backup->failure_reason);
        $this->assertSame('verify', OutboxEvent::query()->withoutTenantScope()->where('event_type', 'backup.failed')->sole()->payload['stage']);
    }

    public function test_no_working_copy_of_the_dump_is_left_behind(): void
    {
        $before = glob(sys_get_temp_dir().'/{fake_dump_,backup_}*', GLOB_BRACE) ?: [];

        app(BackupService::class)->requestScheduled(now());

        $this->assertSame(BackupStatus::Verified, Backup::query()->sole()->status);
        $this->assertSame([], array_values(array_diff(glob(sys_get_temp_dir().'/{fake_dump_,backup_}*', GLOB_BRACE) ?: [], $before)));
    }

    public function test_a_manual_store_backup_is_a_manual_tier_backup_of_that_store(): void
    {
        $store = \App\Domain\Tenancy\Models\Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        $backup = app(BackupService::class)->requestBackup(BackupScope::Store, $store, BackupInitiator::Manual, null)->fresh();

        $this->assertSame(BackupRetentionTier::Manual, $backup->retention_tier);
        $this->assertNull($backup->schedule_key);
        $this->assertSame($store->id, $backup->store_id);
        // The path names the backup, never the store.
        $this->assertStringNotContainsString((string) $store->id.'/', $backup->storage_path);
        $this->assertStringNotContainsString($store->slug, $backup->storage_path);
        $this->assertSame("backups/{$backup->public_id}.sql.gz", $backup->storage_path);
    }

    public function test_the_fake_dump_strategy_is_what_these_tests_run(): void
    {
        // Guards the suite itself: if the binding were lost, these tests
        // would silently start dumping the real test database.
        $this->assertInstanceOf(FakeDatabaseDumpStrategy::class, app(DatabaseDumpStrategy::class));
    }
}
