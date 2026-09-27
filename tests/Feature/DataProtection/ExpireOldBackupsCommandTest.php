<?php

declare(strict_types=1);

namespace Tests\Feature\DataProtection;

use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupRestoreJob;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase B19 — Retention: never deletes a backup required by a restore
 * operation or an active artifact (Module 23 Phase 14, Non-
 * Negotiable).
 * STATUS: NOT EXECUTED — ENVIRONMENT LIMITATION.
 */
final class ExpireOldBackupsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_an_expired_verified_backup_is_deleted(): void
    {
        $store = Store::factory()->create();
        $backup = Backup::factory()->create(['store_id' => $store->id, 'status' => BackupStatus::Verified, 'expires_at' => now()->subDay()]);

        $this->artisan('backups:expire');

        $this->assertSame(BackupStatus::Deleted, $backup->fresh()->status);
    }

    public function test_a_non_expired_backup_is_left_untouched(): void
    {
        $store = Store::factory()->create();
        $backup = Backup::factory()->create(['store_id' => $store->id, 'status' => BackupStatus::Verified, 'expires_at' => now()->addDay()]);

        $this->artisan('backups:expire');

        $this->assertSame(BackupStatus::Verified, $backup->fresh()->status);
    }

    public function test_a_backup_referenced_as_a_pre_restore_safety_snapshot_is_never_deleted_even_if_expired(): void
    {
        $store = Store::factory()->create();
        $preRestoreBackup = Backup::factory()->create(['status' => BackupStatus::Verified, 'expires_at' => now()->subDay()]);
        $backup = Backup::factory()->create(['store_id' => $store->id, 'status' => BackupStatus::Verified]);
        BackupRestoreJob::query()->create([
            'backup_id' => $backup->id, 'target_store_id' => $store->id,
            'pre_restore_backup_id' => $preRestoreBackup->id, 'status' => 'completed',
        ]);

        $this->artisan('backups:expire');

        $this->assertSame(BackupStatus::Verified, $preRestoreBackup->fresh()->status);
    }

    public function test_a_restoring_backup_is_never_deleted_even_if_expired(): void
    {
        $store = Store::factory()->create();
        $backup = Backup::factory()->create(['store_id' => $store->id, 'status' => BackupStatus::Restoring, 'expires_at' => now()->subDay()]);

        $this->artisan('backups:expire');

        $this->assertSame(BackupStatus::Restoring, $backup->fresh()->status);
    }
}
