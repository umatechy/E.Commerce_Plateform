<?php

declare(strict_types=1);

namespace Tests\Feature\DataProtection;

use App\Domain\DataProtection\Models\BackupInitiator;
use App\Domain\DataProtection\Models\BackupScope;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\DataProtection\Services\BackupService;
use App\Domain\DataProtection\Services\Storage\BackupStorageAdapter;
use App\Domain\DataProtection\Services\Storage\LocalBackupStorageAdapter;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FailingDatabaseDumpStrategy;
use Tests\Support\FakeDatabaseDumpStrategy;
use Tests\TestCase;

/**
 * Phase B19 — Backup lifecycle orchestration: request -> execute ->
 * verify, idempotent retry, integrity failure handling (Module 23
 * Phase 12/15, Non-Negotiable). Uses Storage::fake('local') — a real,
 * legitimate Laravel testing facility (not a workaround) — but the
 * actual database dump uses a FAKE strategy, since this sandbox has no
 * real MySQL/mysqldump to exercise.
 * STATUS: NOT EXECUTED — ENVIRONMENT LIMITATION.
 */
final class BackupServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        // Every test drives execute() itself with a fake dump strategy; the
        // RunBackupJob that requestBackup() dispatches would otherwise run
        // synchronously first — with the real mysqldump strategy.
        \Illuminate\Support\Facades\Queue::fake();
    }

    public function test_requesting_a_backup_creates_a_queued_record_and_dispatches_a_job(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);

        $backup = app(BackupService::class)->requestBackup(BackupScope::Store, $store, BackupInitiator::Manual, null);

        $this->assertSame(BackupStatus::Queued, $backup->status);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Domain\DataProtection\Jobs\RunBackupJob::class);
    }

    public function test_executing_a_backup_transitions_through_to_verified(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $backup = app(BackupService::class)->requestBackup(BackupScope::Store, $store, BackupInitiator::Manual, null);

        app(BackupService::class)->execute($backup->fresh(), new FakeDatabaseDumpStrategy(), app(BackupStorageAdapter::class));

        $this->assertSame(BackupStatus::Verified, $backup->fresh()->status);
        $this->assertNotNull($backup->fresh()->checksum_sha256);
        $this->assertNotNull($backup->fresh()->verified_at);
    }

    public function test_executing_an_already_running_backup_is_never_re_executed(): void
    {
        // Module 23 Phase 12 "a retry must not accidentally create
        // uncontrolled duplicate backup artifacts."
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $backup = app(BackupService::class)->requestBackup(BackupScope::Store, $store, BackupInitiator::Manual, null);
        app(BackupService::class)->execute($backup->fresh(), new FakeDatabaseDumpStrategy(), app(BackupStorageAdapter::class));
        $firstChecksum = $backup->fresh()->checksum_sha256;

        // A retried job for the SAME already-Verified backup must be a no-op.
        app(BackupService::class)->execute($backup->fresh(), new FakeDatabaseDumpStrategy(), app(BackupStorageAdapter::class));

        $this->assertSame($firstChecksum, $backup->fresh()->checksum_sha256);
    }

    public function test_a_failed_dump_transitions_the_backup_to_failed_and_records_the_reason(): void
    {
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $backup = app(BackupService::class)->requestBackup(BackupScope::Store, $store, BackupInitiator::Manual, null);

        try {
            app(BackupService::class)->execute($backup->fresh(), new FailingDatabaseDumpStrategy(), app(BackupStorageAdapter::class));
        } catch (\Throwable) {
            // execute() re-throws after recording failure — expected.
        }

        $this->assertSame(BackupStatus::Failed, $backup->fresh()->status);
        $this->assertNotNull($backup->fresh()->failure_reason);
    }

    public function test_the_generated_storage_path_is_opaque_and_never_reveals_the_store_id(): void
    {
        // Non-Negotiable Phase 10 "storage identifiers must not reveal
        // unnecessary tenant information."
        $store = Store::factory()->create();
        app(TenantContext::class)->resolveToStore($store->id);
        $backup = app(BackupService::class)->requestBackup(BackupScope::Store, $store, BackupInitiator::Manual, null);

        app(BackupService::class)->execute($backup->fresh(), new FakeDatabaseDumpStrategy(), app(BackupStorageAdapter::class));

        $this->assertStringNotContainsString((string) $store->id, $backup->fresh()->storage_path);
    }
}
