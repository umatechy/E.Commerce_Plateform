<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Domain\Infrastructure\Services\HostingPreflight;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Running without Redis or a queue worker: the shared hosting deployment. */
final class HostingPreflightTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{name: string, passed: bool, required: bool, detail: string} */
    private function row(string $name): array
    {
        return collect(app(HostingPreflight::class)->run())->firstWhere('name', $name);
    }

    public function test_it_reports_what_is_missing_and_never_prints_a_secret(): void
    {
        Process::fake(['*' => Process::result('mysql  Ver 8.0')]);
        config(['database.connections.mysql.password' => 'Db-S3cret', 'backup.encryption_key' => 'Backup-S3cret', 'app.debug' => true, 'queue.default' => 'sync']);

        $this->assertFalse($this->row('debug_off')['passed']);
        $this->assertFalse($this->row('queue_not_sync')['passed']);
        $this->assertTrue($this->row('backup_key_set')['passed']);
        $this->assertTrue($this->row('driver_tables')['passed']);

        $this->artisan('hosting:preflight')->doesntExpectOutputToContain('S3cret')->assertFailed();
    }

    public function test_a_database_queue_needs_the_scheduler_to_empty_it(): void
    {
        Process::fake(['*' => Process::result('ok')]);
        config(['queue.default' => 'database', 'hosting.queue_from_scheduler' => false]);
        $this->assertFalse($this->row('queue_runs_here')['passed']);

        config(['hosting.queue_from_scheduler' => true]);
        $this->assertTrue($this->row('queue_runs_here')['passed']);
    }

    public function test_the_scheduler_empties_the_queue_only_when_asked(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'queue:work'));
        $this->assertNotNull($event);
        $this->assertStringContainsString('--stop-when-empty', (string) $event->command);
        $this->assertStringContainsString('--queue=outbox,default', (string) $event->command);

        config(['hosting.queue_from_scheduler' => false]);
        $this->assertFalse($event->filtersPass($this->app));

        config(['hosting.queue_from_scheduler' => true]);
        $this->assertTrue($event->filtersPass($this->app));
    }

    /** Outbox events wait on their own queue: a worker that does not read it delivers no notification. */
    public function test_every_worker_reads_the_outbox_queue(): void
    {
        $this->assertStringContainsString('"--queue=outbox,default"', (string) file_get_contents(base_path('docker-compose.prod.yml')));
        $this->assertStringContainsString('--queue=outbox,default', (string) file_get_contents(base_path('deploy/supervisor/umartechy-worker.conf.example')));
    }

    public function test_the_database_queue_and_cache_tables_really_work(): void
    {
        Queue::connection('database')->pushRaw('{"job":"probe"}');
        $this->assertSame(1, DB::table('jobs')->count());

        cache()->store('database')->put('probe', 'value', 60);
        $this->assertSame('value', cache()->store('database')->get('probe'));
        $this->assertTrue(cache()->store('database')->lock('probe-lock', 5)->get());
        $this->assertSame(1, DB::table('cache_locks')->count());
    }
}
