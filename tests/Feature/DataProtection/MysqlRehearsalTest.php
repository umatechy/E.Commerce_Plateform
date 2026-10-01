<?php

declare(strict_types=1);

namespace Tests\Feature\DataProtection;

use App\Domain\DataProtection\Models\BackupRestoreJob;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\DataProtection\Services\BackupService;
use App\Domain\DataProtection\Services\DumpStrategies\DatabaseDumpStrategy;
use App\Domain\DataProtection\Services\DumpStrategies\MysqlClient;
use App\Domain\DataProtection\Services\DumpStrategies\MysqldumpStrategy;
use App\Domain\DataProtection\Services\Rehearsal\MysqlRehearsalTarget;
use App\Domain\DataProtection\Services\Rehearsal\RehearsalTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase B30 (gap G4) — nothing faked: the real `mysqldump` dumps the
 * test database, the artifact is compressed, encrypted and verified,
 * and the real `mysql` client restores it into a throw-away database
 * that is inspected and dropped (Module 23 §23, §47).
 *
 * Needs the mysqldump and mysql binaries. CI has them; locally they must
 * be on PATH (or set BACKUP_MYSQLDUMP_BINARY / BACKUP_MYSQL_BINARY).
 */
final class MysqlRehearsalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function rehearsalDatabases(): array
    {
        return collect(DB::select("SHOW DATABASES LIKE '%\\_rehearsal\\_%'"))->map(fn ($row) => array_values((array) $row)[0])->all();
    }

    public function test_a_real_dump_is_backed_up_encrypted_and_rehearsed_end_to_end(): void
    {
        $this->assertInstanceOf(MysqldumpStrategy::class, app(DatabaseDumpStrategy::class));
        $this->assertInstanceOf(MysqlRehearsalTarget::class, app(RehearsalTarget::class));
        config(['backup.encryption_key' => base64_encode(random_bytes(32))]);
        $before = $this->rehearsalDatabases();

        $backup = app(BackupService::class)->requestScheduled(now())['backup']->fresh();

        $this->assertSame(BackupStatus::Verified, $backup->status, (string) $backup->failure_reason);
        $this->assertTrue($backup->is_encrypted);
        // What is stored is not readable SQL.
        $this->assertStringNotContainsString('CREATE TABLE', Storage::disk('local')->get($backup->storage_path));

        $this->artisan('backups:rehearse')->expectsOutputToContain('Rehearsal passed')->assertSuccessful();

        $rehearsal = BackupRestoreJob::query()->sole();
        $this->assertSame('completed', $rehearsal->status->value, (string) $rehearsal->failure_reason);

        $checks = collect($rehearsal->report['checks'])->keyBy('name');
        foreach (['core_tables_present', 'migration_history_present', 'schema_matches_live', 'relationships_intact', 'tenant_boundaries_intact', 'rehearsal_target_removed'] as $name) {
            $this->assertTrue($checks[$name]['passed'], "{$name}: {$checks[$name]['detail']}");
        }
        // The restored database really had the schema: every migration, every core table.
        $this->assertStringContainsString((string) DB::table('migrations')->count(), $checks['migration_history_present']['detail']);
        $this->assertArrayHasKey('stores', $rehearsal->report['counts']);
        $this->assertGreaterThan(0, $rehearsal->report['import_ms']);

        // The throw-away database is gone and the live one was not touched.
        $this->assertSame($before, $this->rehearsalDatabases());
        $this->assertSame(BackupStatus::Verified, $backup->fresh()->status);
    }

    public function test_a_dump_that_is_not_a_database_fails_the_real_rehearsal_and_leaves_nothing_behind(): void
    {
        $before = $this->rehearsalDatabases();
        $file = (string) tempnam(sys_get_temp_dir(), 'bad_dump_');
        file_put_contents($file, "CREATE TABLE only_one (id INT);\nTHIS IS NOT SQL;\n");

        try {
            app(MysqlRehearsalTarget::class)->rehearse($file);
            $this->fail('A broken dump was imported without an error.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('could not be imported', $e->getMessage());
        } finally {
            @unlink($file);
        }

        $this->assertSame($before, $this->rehearsalDatabases());
    }

    public function test_the_database_password_never_appears_in_a_failure_message(): void
    {
        config(['database.connections.mysql.password' => 'S3cr3t-Wrong-Pass', 'database.connections.mysql.username' => 'no_such_user']);

        try {
            app(MysqlClient::class)->run('mysql', ['-e', 'SELECT 1'], 'the connection failed');
            $this->fail('Connected with credentials that do not exist.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('the connection failed', $e->getMessage());
            $this->assertStringNotContainsString('S3cr3t-Wrong-Pass', $e->getMessage());
            $this->assertStringNotContainsString('mysql_cnf_', $e->getMessage());
        }
    }
}
