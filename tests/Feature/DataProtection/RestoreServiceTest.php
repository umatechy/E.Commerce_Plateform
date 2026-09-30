<?php

declare(strict_types=1);

namespace Tests\Feature\DataProtection;

use App\Domain\DataProtection\Exceptions\BackupNotRestoreEligibleException;
use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\DataProtection\Services\BackupService;
use App\Domain\DataProtection\Services\RestoreService;
use App\Domain\DataProtection\Services\Storage\BackupStorageAdapter;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeDatabaseDumpStrategy;
use Tests\TestCase;

/**
 * Phase B19 — Restore preflight, pre-restore safety backup, platform-
 * level-only execution authority (Module 23 Phase 16-19, Non-
 * Negotiable).
 * STATUS: NOT EXECUTED — ENVIRONMENT LIMITATION.
 */
final class RestoreServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_requesting_a_restore_for_a_verified_backup_succeeds_preflight(): void
    {
        $store = Store::factory()->create();
        $backup = Backup::factory()->create(['store_id' => $store->id, 'status' => BackupStatus::Verified]);

        $restoreJob = app(RestoreService::class)->requestRestore($backup, $store->id, $this->actorId());

        $this->assertSame('requested', $restoreJob->status->value);
    }

    public function test_requesting_a_restore_for_an_unverified_backup_fails_preflight(): void
    {
        $store = Store::factory()->create();
        $backup = Backup::factory()->create(['store_id' => $store->id, 'status' => BackupStatus::Failed]);

        $restoreJob = app(RestoreService::class)->requestRestore($backup, $store->id, $this->actorId());

        $this->assertSame('preflight_failed', $restoreJob->status->value);
        $this->assertNotNull($restoreJob->failure_reason);
    }

    public function test_requesting_a_restore_for_an_expired_backup_fails_preflight(): void
    {
        $store = Store::factory()->create();
        $backup = Backup::factory()->create(['store_id' => $store->id, 'status' => BackupStatus::Verified, 'expires_at' => now()->subDay()]);

        $restoreJob = app(RestoreService::class)->requestRestore($backup, $store->id, $this->actorId());

        $this->assertSame('preflight_failed', $restoreJob->status->value);
    }

    public function test_authorizing_a_restore_takes_a_pre_restore_safety_backup_first(): void
    {
        Queue::fake();
        $store = Store::factory()->create();
        $backup = Backup::factory()->create(['store_id' => $store->id, 'status' => BackupStatus::Verified]);
        $restoreJob = app(RestoreService::class)->requestRestore($backup, $store->id, $this->actorId());

        app(RestoreService::class)->authorizeAndExecute(
            $restoreJob, $this->actorId(), app(BackupService::class), new FakeDatabaseDumpStrategy(), app(BackupStorageAdapter::class),
        );

        $this->assertNotNull($restoreJob->fresh()->pre_restore_backup_id);
        $preRestoreBackup = Backup::query()->find($restoreJob->fresh()->pre_restore_backup_id);
        $this->assertSame('pre_restore_safety', $preRestoreBackup->initiated_by->value);
        $this->assertSame(BackupStatus::Verified, $preRestoreBackup->status); // completed synchronously before the restore itself proceeds
    }

    public function test_authorizing_a_restore_dispatches_the_actual_restore_job_only_after_the_safety_backup(): void
    {
        Queue::fake();
        $store = Store::factory()->create();
        $backup = Backup::factory()->create(['store_id' => $store->id, 'status' => BackupStatus::Verified]);
        $restoreJob = app(RestoreService::class)->requestRestore($backup, $store->id, $this->actorId());

        app(RestoreService::class)->authorizeAndExecute(
            $restoreJob, $this->actorId(), app(BackupService::class), new FakeDatabaseDumpStrategy(), app(BackupStorageAdapter::class),
        );

        Queue::assertPushed(\App\Domain\DataProtection\Jobs\RunRestoreJob::class);
        $this->assertSame('running', $restoreJob->fresh()->status->value);
    }

    public function test_authorizing_an_already_running_restore_job_is_rejected(): void
    {
        $store = Store::factory()->create();
        $backup = Backup::factory()->create(['store_id' => $store->id, 'status' => BackupStatus::Verified]);
        $restoreJob = \App\Domain\DataProtection\Models\BackupRestoreJob::query()->create([
            'backup_id' => $backup->id, 'target_store_id' => $store->id, 'status' => 'running',
        ]);

        $this->expectException(BackupNotRestoreEligibleException::class);
        app(RestoreService::class)->authorizeAndExecute(
            $restoreJob, $this->actorId(), app(BackupService::class), new FakeDatabaseDumpStrategy(), app(BackupStorageAdapter::class),
        );
    }
}
