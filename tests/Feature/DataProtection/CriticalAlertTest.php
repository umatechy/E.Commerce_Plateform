<?php

declare(strict_types=1);

namespace Tests\Feature\DataProtection;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupInitiator;
use App\Domain\DataProtection\Models\BackupRestoreJob;
use App\Domain\DataProtection\Models\BackupScope;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\DataProtection\Services\BackupService;
use App\Domain\DataProtection\Services\DumpStrategies\DatabaseDumpStrategy;
use App\Domain\DataProtection\Services\Rehearsal\RehearsalTarget;
use App\Domain\DataProtection\Services\RestoreRehearsalService;
use App\Domain\DataProtection\Services\Storage\BackupStorageAdapter;
use App\Domain\Events\Models\OutboxEvent;
use App\Domain\Notifications\Models\NotificationChannel;
use App\Domain\Notifications\Models\NotificationMessageType;
use App\Domain\Notifications\Models\NotificationStatus;
use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Settings\Exceptions\InvalidSettingValueException;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FailingDatabaseDumpStrategy;
use Tests\Support\FakeRehearsalTarget;
use Tests\Support\FlakyBackupStorageAdapter;
use Tests\TestCase;

/**
 * Phase B30 (gap G4) — critical alerts go out through the existing
 * notification pipeline, once per incident, to the platform's operators
 * only (SRS HEALTH-005; owner decision 2026-09-30 §4).
 */
final class CriticalAlertTest extends TestCase
{
    use InteractsWithBackups, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups();
    }

    private function setPlatform(string $key, mixed $value): void
    {
        app(TenantContext::class)->resolveToPlatform();
        app(ConfigService::class)->set($key, $value, SettingScope::Platform, null);
    }

    private function failScheduledBackup(): Backup
    {
        $this->app->bind(DatabaseDumpStrategy::class, FailingDatabaseDumpStrategy::class);

        try {
            app(BackupService::class)->requestScheduled(now());
        } catch (\Throwable) {
        }

        return Backup::query()->latest('id')->firstOrFail();
    }

    public function test_a_failed_scheduled_backup_emails_every_active_platform_staff_member(): void
    {
        Mail::fake();
        $ops = $this->platformStaff(['email' => 'ops@platform.test']);
        $second = $this->platformStaff(['email' => 'second@platform.test']);
        $this->platformStaff(['email' => 'left@platform.test', 'is_active' => false]);
        $owner = $this->ownerOf(Store::factory()->create());

        \App\Support\RequestId::begin('backup-run'); // as the scheduled command does
        $backup = $this->failScheduledBackup();
        $this->deliverEvents();

        $alerts = $this->alerts('backup.failed');
        $this->assertEqualsCanonicalizing(['ops@platform.test', 'second@platform.test'], $alerts->pluck('destination')->all());
        $this->assertNotContains($owner->email, $alerts->pluck('destination')->all());

        $alert = $alerts->firstWhere('destination', 'ops@platform.test');
        $this->assertSame('[CRITICAL] Scheduled backup failed', $alert->subject);
        $this->assertSame(NotificationMessageType::System, $alert->message_type);
        $this->assertSame(NotificationChannel::Email, $alert->channel);
        $this->assertSame($ops->id, $alert->recipient_id);
        $this->assertNull($alert->store_id); // a platform record, in no store
        $this->assertSame(NotificationStatus::Sent, $alert->status); // delivered through the normal delivery job
        $this->assertStringContainsString($backup->public_id, $alert->body);
        $this->assertStringContainsString('simulated mysqldump failure', $alert->body);
        $this->assertStringContainsString($backup->request_id, $alert->body);
        $this->assertStringContainsString('incident-response-runbook.md', $alert->body);

        $this->assertSame(1, AuditLog::query()->where('action', 'alert.raised')->count());
        $this->assertSame($second->id, $alerts->firstWhere('destination', 'second@platform.test')->recipient_id);
    }

    public function test_configured_recipients_replace_the_staff_fallback(): void
    {
        $this->platformStaff(['email' => 'staff@platform.test']);
        $this->setPlatform('alerts.critical_email_recipients', ['oncall@platform.test', 'backup-lead@platform.test']);

        $this->failScheduledBackup();
        $this->deliverEvents();

        $this->assertEqualsCanonicalizing(['oncall@platform.test', 'backup-lead@platform.test'], $this->alerts()->pluck('destination')->all());
    }

    public function test_recipient_settings_refuse_values_that_would_reach_nobody(): void
    {
        try {
            $this->setPlatform('alerts.critical_email_recipients', ['not-an-email']);
            $this->fail('Accepted an invalid alert address.');
        } catch (InvalidSettingValueException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidSettingValueException::class);
        $this->setPlatform('alerts.whatsapp_recipients', ['0300-1234567']);
    }

    public function test_the_same_incident_alerts_once_and_a_new_incident_alerts_again(): void
    {
        $this->platformStaff();
        Carbon::setTestNow('2026-10-14 02:00:00');
        $first = $this->failScheduledBackup();
        $this->deliverEvents();
        $this->assertCount(1, $this->alerts());

        // The event is consumed again (an outbox redelivery, a retried consumer).
        OutboxEvent::query()->withoutTenantScope()->update(['status' => 'pending']);
        $this->deliverEvents();
        $this->assertCount(1, $this->alerts());
        $this->assertSame(1, AuditLog::query()->where('action', 'alert.raised')->count());

        // The queue retries the failed backup job: no new event, no new alert.
        try {
            (new \App\Domain\DataProtection\Jobs\RunBackupJob($first->id))->handle(app(BackupService::class), app(DatabaseDumpStrategy::class), app(BackupStorageAdapter::class));
        } catch (\Throwable) {
        }
        $this->deliverEvents();
        $this->assertCount(1, $this->alerts());

        // Tomorrow's failure is a different incident.
        Carbon::setTestNow('2026-10-15 02:00:00');
        $this->failScheduledBackup();
        $this->deliverEvents();
        $this->assertCount(2, $this->alerts());
    }

    public function test_storage_and_verification_failures_say_so(): void
    {
        $this->platformStaff();

        $storage = new FlakyBackupStorageAdapter();
        $storage->failStore = true;
        $this->app->instance(BackupStorageAdapter::class, $storage);
        try {
            app(BackupService::class)->requestScheduled(now());
        } catch (\Throwable) {
        }

        $this->app->forgetInstance(BackupStorageAdapter::class);
        $stored = $this->platformBackup();
        $this->corrupt($stored);
        $this->artisan('backups:verify');

        $this->deliverEvents();

        $this->assertSame('[CRITICAL] Backup storage failure', $this->alerts('backup.failed')->sole()->subject);
        $recheck = $this->alerts('backup.verification_failed')->sole();
        $this->assertSame('[CRITICAL] Stored backup is damaged or missing', $recheck->subject);
        $this->assertStringContainsString($stored->public_id, $recheck->body);
    }

    public function test_rehearsal_restore_and_cleanup_failures_alert(): void
    {
        $this->platformStaff();

        // Rehearsal failure.
        $this->app->instance(RehearsalTarget::class, new FakeRehearsalTarget(['relationships_intact']));
        app(RestoreRehearsalService::class)->rehearse($this->platformBackup(), null);

        // Production restore failure (the event the restore job records).
        $restore = BackupRestoreJob::query()->create(['backup_id' => $this->platformBackup()->id, 'status' => 'failed', 'reference' => 'Incident INC-7']);
        \Illuminate\Support\Facades\DB::transaction(fn () => app(\App\Domain\Events\Support\RecordsOutboxEvents::class)->recordEventFor(null, 'restore.failed', [
            'restore_job_id' => $restore->id, 'backup_public_id' => $restore->backup->public_id, 'reason' => 'import failed', 'reference' => 'Incident INC-7',
        ], "restore_job:{$restore->id}:failed"));

        // Cleanup failure.
        $stuck = $this->platformBackup(['expires_at' => now()->subDay(), 'verified_at' => now()->subDays(40)]);
        $storage = new FlakyBackupStorageAdapter();
        $storage->failDeleteOf = [$stuck->storage_path];
        $this->app->instance(BackupStorageAdapter::class, $storage);
        $this->artisan('backups:expire');
        $this->artisan('backups:expire'); // again the same day: still one alert

        $this->deliverEvents();

        $this->assertSame('[CRITICAL] Restore rehearsal failed', $this->alerts('restore.rehearsal_failed')->sole()->subject);
        $this->assertSame('[CRITICAL] PRODUCTION RESTORE FAILED', $this->alerts('restore.failed')->sole()->subject);
        $this->assertStringContainsString('no automatic rollback', $this->alerts('restore.failed')->sole()->body);
        $this->assertSame('[CRITICAL] Backup cleanup failed', $this->alerts('backup.retention_cleanup_failed')->sole()->subject);
    }

    public function test_an_overdue_backup_and_repeated_failures_alert_once_a_day(): void
    {
        $this->platformStaff();
        Carbon::setTestNow('2026-10-14 09:00:00');

        // A healthy platform: a verified backup from this morning.
        $this->platformBackup(['verified_at' => now()->subHours(7)]);
        $this->artisan('backups:monitor')->expectsOutputToContain('on schedule')->assertSuccessful();

        // 30 hours later nothing new has been verified: past the 24-hour target.
        Carbon::setTestNow('2026-10-15 15:00:00');
        $this->artisan('backups:monitor')->expectsOutputToContain('Backup overdue')->assertFailed();
        $this->artisan('backups:monitor')->assertFailed(); // the next hourly run
        $this->deliverEvents();

        $overdue = $this->alerts('backup.overdue');
        $this->assertCount(1, $overdue);
        $this->assertSame('[CRITICAL] No recent backup', $overdue->first()->subject);
        $this->assertStringContainsString('24 hours', $overdue->first()->body);

        // Two scheduled backups in a row failed.
        foreach (['platform:daily:2026-10-14', 'platform:daily:2026-10-15'] as $key) {
            Backup::factory()->create(['scope' => BackupScope::Platform, 'store_id' => null, 'status' => BackupStatus::Failed, 'initiated_by' => BackupInitiator::Scheduled, 'schedule_key' => $key, 'failure_reason' => 'disk full']);
        }
        $this->artisan('backups:monitor')->expectsOutputToContain('Repeated failure')->assertFailed();
        $this->deliverEvents();
        $this->assertSame('[CRITICAL] Backups keep failing', $this->alerts('backup.repeated_failure')->sole()->subject);

        // The next day the condition still holds: one more alert each, not one per hour.
        Carbon::setTestNow('2026-10-16 03:00:00');
        $this->artisan('backups:monitor');
        $this->artisan('backups:monitor');
        $this->deliverEvents();
        $this->assertCount(2, $this->alerts('backup.overdue'));
        $this->assertCount(2, $this->alerts('backup.repeated_failure'));
    }

    public function test_a_new_platform_with_no_backup_yet_is_not_overdue(): void
    {
        $this->platformStaff();
        Store::factory()->create(['created_at' => now()->subHours(2)]);

        $this->artisan('backups:monitor')->assertSuccessful();

        Store::factory()->create(['created_at' => now()->subDays(3)]);
        $this->artisan('backups:monitor')->assertFailed();
    }

    public function test_whatsapp_is_off_by_default_and_recorded_honestly_when_no_provider_exists(): void
    {
        Mail::fake();
        $this->platformStaff(['email' => 'ops@platform.test']);

        $this->failScheduledBackup();
        $this->deliverEvents();
        $this->assertSame(0, $this->alerts()->where('channel', NotificationChannel::WhatsApp)->count());

        $this->setPlatform('alerts.whatsapp_enabled', true);
        $this->setPlatform('alerts.whatsapp_recipients', ['+923001234567']);
        Carbon::setTestNow(now()->addDay());
        $this->failScheduledBackup();
        $this->deliverEvents();

        $whatsapp = $this->alerts()->where('channel', NotificationChannel::WhatsApp)->sole();
        // No WhatsApp provider is connected (gap G10): the attempt is on record as failed, not as sent.
        $this->assertSame(NotificationStatus::Failed, $whatsapp->status);
        $this->assertSame('channel_not_configured', $whatsapp->attempts()->sole()->failure_code);
        // Email, the mandatory channel, still went out.
        $this->assertSame(NotificationStatus::Sent, $this->alerts()->where('channel', NotificationChannel::Email)->last()->status);
    }

    public function test_alerts_about_a_stores_backup_stay_out_of_that_stores_records(): void
    {
        $staff = $this->platformStaff(['email' => 'ops@platform.test']);
        $store = Store::factory()->create();
        $owner = $this->ownerOf($store);
        $this->app->bind(DatabaseDumpStrategy::class, FailingDatabaseDumpStrategy::class);

        // The store's own manual backup fails.
        try {
            $this->actingAs($owner)->postJson('/api/v1/backups');
        } catch (\Throwable) {
        }
        $this->deliverEvents();

        $alert = $this->alerts('backup.failed')->sole();
        $this->assertNull($alert->store_id);
        $this->assertSame($staff->email, $alert->destination);

        // The store's message log does not show it, nor any staff address.
        $response = $this->actingAs($owner)->getJson('/api/v1/notification-messages')->assertOk();
        $this->assertStringNotContainsString('ops@platform.test', $response->getContent());
        $this->assertStringNotContainsString('CRITICAL', $response->getContent());

        // Consuming the store's event put the store's context back afterwards.
        $context = app(TenantContext::class);
        $context->resolveToStore($store->id);
        $context->asPlatform(fn () => $this->assertTrue($context->isPlatform()));
        $this->assertFalse($context->isPlatform());
        $this->assertSame($store->id, $context->storeId());
    }

    public function test_alerts_carry_no_credentials(): void
    {
        $this->platformStaff();
        config(['database.connections.mysql.password' => 'S3cr3t-Db-Pass', 'backup.encryption_key' => base64_encode(random_bytes(32))]);

        $this->failScheduledBackup();
        $this->platformBackup();
        $this->deliverEvents();

        $recorded = $this->alerts()->map(fn ($m) => $m->subject.$m->body)->implode('')
            .AuditLog::query()->get()->map(fn ($e) => $e->getRawOriginal('context'))->implode('')
            .Backup::query()->get()->toJson();

        $this->assertStringNotContainsString('S3cr3t-Db-Pass', $recorded);
        $this->assertStringNotContainsString((string) config('backup.encryption_key'), $recorded);
        $this->assertStringNotContainsString((string) config('app.key'), $recorded);
    }
}
