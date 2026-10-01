<?php

declare(strict_types=1);

namespace Tests\Feature\DataProtection;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\DataProtection\Exceptions\BackupNotRestoreEligibleException;
use App\Domain\DataProtection\Jobs\RunRestoreJob;
use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupRestoreJob;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\DataProtection\Models\RestoreMode;
use App\Domain\DataProtection\Services\BackupArtifactCodec;
use App\Domain\DataProtection\Services\BackupService;
use App\Domain\DataProtection\Services\DumpStrategies\DatabaseDumpStrategy;
use App\Domain\DataProtection\Services\DumpStrategies\DatabaseRestoreStrategy;
use App\Domain\DataProtection\Services\RestoreService;
use App\Domain\DataProtection\Services\Storage\BackupStorageAdapter;
use App\Domain\Events\Models\OutboxEvent;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FailingDatabaseDumpStrategy;
use Tests\Support\FakeDatabaseDumpStrategy;
use Tests\TestCase;

/**
 * Phase B30 (gap G4) — a production restore replaces the live database,
 * so nothing starts unless every check passes (Module 23 §33–35;
 * SRS BKP-005, SA-004).
 */
final class RestoreSafetyTest extends TestCase
{
    use InteractsWithBackups, RefreshDatabase;

    private const REFERENCE = 'Incident INC-0042';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups();
    }

    private function request(Backup $backup): BackupRestoreJob
    {
        return BackupRestoreJob::query()->create(['backup_id' => $backup->id, 'target_store_id' => $backup->store_id, 'status' => 'requested']);
    }

    private function authorize(BackupRestoreJob $job, ?string $confirmation = null, string $reference = self::REFERENCE): void
    {
        app(RestoreService::class)->authorizeAndExecute($job, $this->actorId(), $confirmation ?? $job->backup->public_id, $reference, app(DatabaseDumpStrategy::class));
    }

    private function runRestore(BackupRestoreJob $job, DatabaseRestoreStrategy $restorer, ?string $lockOwner = null): void
    {
        (new RunRestoreJob($job->id, $lockOwner))->handle($restorer, app(BackupStorageAdapter::class), app(BackupArtifactCodec::class), app(RecordsOutboxEvents::class), app(AuditLogger::class));
    }

    /** A restore strategy that records the SQL it was given, or fails. */
    private function restorer(bool $fails = false): DatabaseRestoreStrategy
    {
        return new class($fails) implements DatabaseRestoreStrategy
        {
            public ?string $restored = null;

            public function __construct(private readonly bool $fails) {}

            public function restore(string $localSqlFilePath): void
            {
                if ($this->fails) {
                    throw new \RuntimeException('simulated import failure at line 4821');
                }

                $this->restored = (string) file_get_contents($localSqlFilePath);
            }
        };
    }

    // --- Preflight ---

    public function test_a_request_for_a_backup_whose_artifact_is_gone_or_changed_fails_preflight(): void
    {
        $store = Store::factory()->create();
        $missing = Backup::factory()->create(['store_id' => $store->id]); // a row, no artifact
        $changed = Backup::factory()->withArtifact()->create(['store_id' => $store->id]);
        $this->corrupt($changed);

        foreach ([$missing, $changed] as $backup) {
            $job = app(RestoreService::class)->requestRestore($backup, $store->id, $this->actorId());

            $this->assertSame('preflight_failed', $job->status->value);
            $this->assertStringContainsString('integrity check', (string) $job->failure_reason);
        }

        $this->assertSame(2, AuditLog::query()->where('action', 'restore.preflight_failed')->count());
        $this->assertSame(0, OutboxEvent::query()->withoutTenantScope()->where('event_type', 'restore.requested')->count());
    }

    public function test_a_backup_that_went_bad_after_the_request_is_refused_at_authorization(): void
    {
        Queue::fake();
        $backup = $this->platformBackup();
        $job = $this->request($backup);
        $this->corrupt($backup);

        try {
            $this->authorize($job);
            $this->fail('A restore from a damaged backup was authorized.');
        } catch (BackupNotRestoreEligibleException $e) {
            $this->assertStringContainsString('integrity check', $e->getMessage());
        }

        $this->assertSame('requested', $job->fresh()->status->value);
        $this->assertNull($job->fresh()->pre_restore_backup_id);
        Queue::assertNotPushed(RunRestoreJob::class);
        // No safety backup was taken for a restore that never began.
        $this->assertSame(1, Backup::query()->count());
    }

    // --- Explicit confirmation ---

    public function test_authorization_needs_the_backup_id_typed_back_and_a_reference(): void
    {
        Queue::fake();
        $backup = $this->platformBackup();
        $job = $this->request($backup);

        foreach ([['wrong-id', self::REFERENCE], [$backup->public_id, 'x'], ['', self::REFERENCE]] as [$confirmation, $reference]) {
            try {
                $this->authorize($job, $confirmation, $reference);
                $this->fail('Authorized without a valid confirmation and reference.');
            } catch (BackupNotRestoreEligibleException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('requested', $job->fresh()->status->value);
        $this->assertSame(1, Backup::query()->count());
        Queue::assertNotPushed(RunRestoreJob::class);

        $this->authorize($job);

        $job->refresh();
        $this->assertSame('running', $job->status->value);
        $this->assertSame(self::REFERENCE, $job->reference);
        $this->assertNotNull($job->pre_restore_backup_id);
        $this->assertSame(self::REFERENCE, AuditLog::query()->where('action', 'restore.authorized')->sole()->contextData()['reference']);
        Queue::assertPushed(RunRestoreJob::class, 1);
    }

    public function test_the_api_refuses_an_unconfirmed_restore_and_changes_nothing(): void
    {
        Queue::fake();
        $backup = $this->platformBackup();
        $job = $this->request($backup);
        $staff = $this->platformStaff();
        $url = "/api/v1/super-admin/restore-jobs/{$job->id}/authorize";

        $this->actingAs($staff)->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['confirmation', 'reference']);
        $this->postJson($url, ['confirmation' => 'not-the-backup', 'reference' => self::REFERENCE])->assertStatus(422)->assertJsonPath('code', 'restore_refused');

        $this->assertSame('requested', $job->fresh()->status->value);
        Queue::assertNotPushed(RunRestoreJob::class);

        $this->postJson($url, ['confirmation' => $backup->public_id, 'reference' => self::REFERENCE])->assertOk()->assertJsonPath('data.status', 'running')->assertJsonPath('data.reference', self::REFERENCE);
    }

    public function test_the_restore_route_needs_step_up_and_mfa(): void
    {
        Queue::fake();
        $backup = $this->platformBackup();
        $job = $this->request($backup);
        $body = ['confirmation' => $backup->public_id, 'reference' => self::REFERENCE];
        $url = "/api/v1/super-admin/restore-jobs/{$job->id}/authorize";

        config(['security.step_up.enabled' => true]);
        $this->actingAs($this->platformStaff())->postJson($url, $body)->assertStatus(403)->assertJsonPath('code', 'step_up_required');

        config(['security.step_up.enabled' => false, 'security.mfa.required_for_platform_staff' => true]);
        $this->postJson($url, $body)->assertStatus(403)->assertJsonPath('code', 'mfa_enrollment_required');

        $this->assertSame('requested', $job->fresh()->status->value);
        Queue::assertNotPushed(RunRestoreJob::class);
    }

    // --- Concurrency ---

    public function test_only_one_production_restore_can_run_at_a_time(): void
    {
        Queue::fake();
        $first = $this->request($this->platformBackup());
        $second = $this->request($this->platformBackup());

        $this->authorize($first);

        try {
            $this->authorize($second);
            $this->fail('A second production restore was authorized while one was running.');
        } catch (BackupNotRestoreEligibleException $e) {
            $this->assertStringContainsString('another production restore', $e->getMessage());
        }

        $this->assertSame('requested', $second->fresh()->status->value);
        Queue::assertPushed(RunRestoreJob::class, 1);
    }

    public function test_the_same_request_cannot_be_authorized_twice(): void
    {
        Queue::fake();
        $job = $this->request($this->platformBackup());
        $this->authorize($job);

        $this->expectException(BackupNotRestoreEligibleException::class);
        $this->authorize($job->fresh());
    }

    public function test_a_failed_safety_backup_stops_the_restore_and_frees_the_lock(): void
    {
        Queue::fake([RunRestoreJob::class]);
        $backup = $this->platformBackup();
        $job = $this->request($backup);
        $this->app->bind(DatabaseDumpStrategy::class, FailingDatabaseDumpStrategy::class);

        try {
            $this->authorize($job);
            $this->fail('The restore went ahead without a safety backup.');
        } catch (BackupNotRestoreEligibleException $e) {
            $this->assertStringContainsString('safety backup', $e->getMessage());
        }

        $this->assertSame('requested', $job->fresh()->status->value);
        Queue::assertNotPushed(RunRestoreJob::class);

        // The lock is free again: with a working dump the restore can be authorized.
        $this->app->bind(DatabaseDumpStrategy::class, FakeDatabaseDumpStrategy::class);
        $this->authorize($job->fresh());
        $this->assertSame('running', $job->fresh()->status->value);
    }

    public function test_a_rehearsal_record_can_never_be_authorized_as_a_production_restore(): void
    {
        $backup = $this->platformBackup();
        $rehearsal = BackupRestoreJob::query()->create(['backup_id' => $backup->id, 'mode' => RestoreMode::Rehearsal, 'status' => 'requested']);

        $this->expectExceptionMessage('rehearsal record');
        $this->authorize($rehearsal);
    }

    // --- Execution ---

    public function test_the_restore_job_decodes_the_artifact_and_records_the_real_outcome(): void
    {
        Queue::fake();
        config(['backup.encryption_key' => base64_encode(random_bytes(32))]);
        // A real, encrypted backup made by the service itself.
        $backup = app(BackupService::class)->requestScheduled(now())['backup'];
        (new \App\Domain\DataProtection\Jobs\RunBackupJob($backup->id))->handle(app(BackupService::class), app(DatabaseDumpStrategy::class), app(BackupStorageAdapter::class));
        $job = $this->request($backup->fresh());
        $this->authorize($job);

        $restorer = $this->restorer();
        $lockOwner = null;
        Queue::assertPushed(RunRestoreJob::class, function (RunRestoreJob $pushed) use (&$lockOwner) {
            $lockOwner = $pushed->lockOwner;

            return true;
        });
        $this->runRestore($job, $restorer, $lockOwner);

        // The strategy received the plain dump, not the encrypted artifact.
        $this->assertSame("-- fake sql dump for test purposes\n", $restorer->restored);

        $job->refresh();
        $this->assertSame('completed', $job->status->value);
        $this->assertNotNull($job->completed_at);
        $this->assertNotNull($job->duration_ms);
        foreach (['restore.authorized', 'restore.started', 'restore.completed'] as $action) {
            $this->assertSame(1, AuditLog::query()->where('action', $action)->count(), $action);
        }

        // The lock was released: another restore could be authorized now.
        $this->assertTrue(Cache::lock(RestoreService::LOCK, 5)->get());
        // Running the same job again does nothing.
        $again = $this->restorer();
        $this->runRestore($job, $again);
        $this->assertNull($again->restored);
    }

    public function test_a_failed_restore_is_recorded_as_failed_with_its_safety_backup(): void
    {
        Queue::fake();
        $backup = $this->platformBackup();
        $job = $this->request($backup);
        $this->authorize($job);

        $this->runRestore($job, $this->restorer(fails: true));

        $job->refresh();
        $this->assertSame('failed', $job->status->value);
        $this->assertStringContainsString('simulated import failure', (string) $job->failure_reason);
        $this->assertNotNull($job->completed_at);

        $event = OutboxEvent::query()->withoutTenantScope()->where('event_type', 'restore.failed')->sole();
        $this->assertSame(self::REFERENCE, $event->payload['reference']);
        $this->assertSame($job->preRestoreBackup->public_id, $event->payload['pre_restore_backup_public_id']);
        // The safety backup taken before the restore is there to roll forward from.
        $this->assertSame(BackupStatus::Verified, $job->preRestoreBackup->status);
        $this->assertTrue(Storage::disk('local')->exists($job->preRestoreBackup->storage_path));
        $this->assertSame(0, AuditLog::query()->where('action', 'restore.completed')->count());
    }

    public function test_no_copy_of_the_dump_is_left_on_local_disk_after_a_restore(): void
    {
        Queue::fake();
        $job = $this->request($this->platformBackup());
        $this->authorize($job);
        $before = glob(sys_get_temp_dir().'/backup_*') ?: [];

        $this->runRestore($job, $this->restorer());

        $this->assertSame([], array_values(array_diff(glob(sys_get_temp_dir().'/backup_*') ?: [], $before)));
    }
}
