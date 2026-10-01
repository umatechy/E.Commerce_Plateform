<?php

declare(strict_types=1);

namespace Tests\Feature\DataProtection;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\DataProtection\Exceptions\BackupNotRestoreEligibleException;
use App\Domain\DataProtection\Jobs\RunRestoreRehearsalJob;
use App\Domain\DataProtection\Models\BackupRestoreJob;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\DataProtection\Models\RestoreMode;
use App\Domain\DataProtection\Services\BackupService;
use App\Domain\DataProtection\Services\Rehearsal\RehearsalTarget;
use App\Domain\DataProtection\Services\RestoreRehearsalService;
use App\Domain\Events\Models\OutboxEvent;
use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeRehearsalTarget;
use Tests\TestCase;

/**
 * Phase B30 (gap G4) — the restore rehearsal: what is recorded is what
 * happened (Module 23 §47; SRS BKP-007, TEST-011). The isolated database
 * is faked here; MysqlRehearsalTest runs the real one.
 */
final class RestoreRehearsalTest extends TestCase
{
    use InteractsWithBackups, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups();
    }

    private function target(array $failingChecks = [], ?\Throwable $throws = null): FakeRehearsalTarget
    {
        $target = new FakeRehearsalTarget($failingChecks, $throws);
        $this->app->instance(RehearsalTarget::class, $target);

        return $target;
    }

    private function failedEvents(): int
    {
        return OutboxEvent::query()->withoutTenantScope()->where('event_type', 'restore.rehearsal_failed')->count();
    }

    public function test_a_rehearsal_restores_the_decoded_dump_into_the_target_and_records_the_result(): void
    {
        $target = $this->target();
        config(['backup.encryption_key' => base64_encode(random_bytes(32))]);
        $backup = app(BackupService::class)->requestScheduled(now())['backup']->fresh();

        $this->artisan('backups:rehearse')->expectsOutputToContain('Rehearsal passed')->assertSuccessful();

        // The target was given the plain dump of an encrypted, compressed artifact.
        $this->assertSame(["-- fake sql dump for test purposes\n"], $target->received);

        $rehearsal = BackupRestoreJob::query()->sole();
        $this->assertSame(RestoreMode::Rehearsal, $rehearsal->mode);
        $this->assertSame('completed', $rehearsal->status->value);
        $this->assertSame($backup->id, $rehearsal->backup_id);
        $this->assertNotNull($rehearsal->duration_ms);
        $this->assertTrue($rehearsal->report['passed']);
        $this->assertCount(6, $rehearsal->report['checks']);
        $this->assertSame(4, $rehearsal->report['rto_target_hours']);
        $this->assertTrue($rehearsal->report['within_rto_target']);
        $this->assertStringStartsWith('backup-rehearsal-', (string) $rehearsal->request_id);

        $this->assertSame(1, AuditLog::query()->where('action', 'restore.rehearsal_started')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'restore.rehearsal_completed')->count());
        $this->assertSame(0, $this->failedEvents());
        // The backup itself is untouched and still restorable.
        $this->assertSame(BackupStatus::Verified, $backup->fresh()->status);
    }

    public function test_a_rehearsal_whose_checks_fail_is_failed_and_raises_an_event(): void
    {
        $this->target(['tenant_boundaries_intact']);
        $this->platformBackup();

        $this->artisan('backups:rehearse')->expectsOutputToContain('REHEARSAL FAILED')->assertFailed();

        $rehearsal = BackupRestoreJob::query()->sole();
        $this->assertSame('failed', $rehearsal->status->value);
        $this->assertStringContainsString('tenant_boundaries_intact', (string) $rehearsal->failure_reason);
        $this->assertNull($rehearsal->report['within_rto_target']);
        $this->assertSame(1, $this->failedEvents());
        $this->assertSame(1, AuditLog::query()->where('action', 'restore.rehearsal_failed')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'restore.rehearsal_completed')->count());
    }

    public function test_a_rehearsal_that_cannot_run_is_failed_never_completed(): void
    {
        $this->target(throws: new \RuntimeException("the rehearsal database could not be created: Access denied for user 'app'@'%'"));
        $this->platformBackup();

        $this->artisan('backups:rehearse')->assertFailed();

        $rehearsal = BackupRestoreJob::query()->sole();
        $this->assertSame('failed', $rehearsal->status->value);
        $this->assertStringContainsString('Access denied', (string) $rehearsal->failure_reason);
        $this->assertSame(1, $this->failedEvents());
    }

    public function test_a_damaged_backup_fails_the_rehearsal_before_anything_is_imported(): void
    {
        $target = $this->target();
        $backup = $this->platformBackup();
        $this->corrupt($backup);

        $rehearsal = app(RestoreRehearsalService::class)->rehearse($backup, null);

        $this->assertSame('failed', $rehearsal->status->value);
        $this->assertStringContainsString('does not match', (string) $rehearsal->failure_reason);
        $this->assertSame([], $target->received);
    }

    public function test_an_unverified_or_expired_backup_is_not_rehearsed_as_if_it_were_good(): void
    {
        $target = $this->target();

        foreach ([['status' => BackupStatus::Failed], ['expires_at' => now()->subDay()]] as $attributes) {
            $rehearsal = app(RestoreRehearsalService::class)->rehearse($this->platformBackup($attributes), null);
            $this->assertSame('failed', $rehearsal->status->value);
        }

        $this->assertSame([], $target->received);
    }

    public function test_the_newest_verified_platform_backup_is_the_one_rehearsed(): void
    {
        $this->target();
        $this->platformBackup(['verified_at' => now()->subDays(3)]);
        $newest = $this->platformBackup(['verified_at' => now()->subHour()]);
        $this->platformBackup(['verified_at' => now(), 'status' => BackupStatus::Failed]);

        $this->artisan('backups:rehearse')->assertSuccessful();

        $this->assertSame($newest->id, BackupRestoreJob::query()->sole()->backup_id);
    }

    public function test_two_rehearsals_cannot_run_at_once(): void
    {
        $this->target();
        $backup = $this->platformBackup();
        $running = Cache::lock(RestoreRehearsalService::LOCK, 60);
        $running->get();

        try {
            app(RestoreRehearsalService::class)->rehearse($backup, null);
            $this->fail('A second rehearsal started while one was running.');
        } catch (BackupNotRestoreEligibleException $e) {
            $this->assertStringContainsString('another restore rehearsal', $e->getMessage());
        }

        $this->artisan('backups:rehearse')->expectsOutputToContain('did not start')->assertFailed();
        $this->assertSame(0, BackupRestoreJob::query()->count());

        // The lock is released after a rehearsal, also a failed one.
        $running->release();
        $this->target(throws: new \RuntimeException('boom'));
        app(RestoreRehearsalService::class)->rehearse($backup, null);
        $this->assertTrue(Cache::lock(RestoreRehearsalService::LOCK, 5)->get());
    }

    public function test_the_scheduled_rehearsal_respects_the_setting_and_reports_a_missing_backup(): void
    {
        $this->target();
        $this->artisan('backups:rehearse')->expectsOutputToContain('no verified backup')->assertFailed();

        $this->platformBackup();
        app(TenantContext::class)->resolveToPlatform();
        app(ConfigService::class)->set('backup.rehearsal_enabled', false, SettingScope::Platform, null);

        $this->artisan('backups:rehearse')->expectsOutputToContain('switched off')->assertSuccessful();
        $this->assertSame(0, BackupRestoreJob::query()->count());

        $this->artisan('backups:rehearse --force')->assertSuccessful();
        $this->assertSame(1, BackupRestoreJob::query()->count());
    }

    public function test_platform_staff_can_queue_a_rehearsal_and_see_its_report(): void
    {
        $this->target();
        $backup = $this->platformBackup();
        $staff = $this->platformStaff();

        Queue::fake();
        $this->actingAs($staff)->postJson("/api/v1/super-admin/backups/{$backup->public_id}/rehearse")->assertStatus(202)->assertJsonPath('data.queued', true);
        Queue::assertPushed(RunRestoreRehearsalJob::class, fn (RunRestoreRehearsalJob $job) => $job->backupId === $backup->id && $job->requestedByUserId === $staff->id);

        (new RunRestoreRehearsalJob($backup->id, $staff->id))->handle(app(RestoreRehearsalService::class));

        $this->getJson('/api/v1/super-admin/restore-jobs')->assertOk()
            ->assertJsonPath('data.data.0.mode', 'rehearsal')
            ->assertJsonPath('data.data.0.status', 'completed')
            ->assertJsonPath('data.data.0.report.passed', true);
        $this->getJson('/api/v1/super-admin/backups/summary')->assertOk()
            ->assertJsonPath('data.last_rehearsal_status', 'completed')
            ->assertJsonPath('data.rto_target_hours', 4);

        // A failed or expired backup cannot be queued for a rehearsal.
        $failed = $this->platformBackup(['status' => BackupStatus::Failed]);
        $this->postJson("/api/v1/super-admin/backups/{$failed->public_id}/rehearse")->assertStatus(422);
    }
}
