<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

/**
 * What this server really offers, checked by trying it. Read-only, apart from
 * one short lock. Results say pass or fail and name what to change; they never
 * contain a password or key.
 *
 * "required" stops a deployment; the others are for the owner to weigh.
 */
final class HostingPreflight
{
    private const EXTENSIONS = ['bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_mysql', 'sodium', 'tokenizer', 'xml', 'zip'];

    /** Symfony Process needs these; the scheduler and backups run through it. */
    private const FUNCTIONS = ['proc_open', 'proc_close', 'proc_get_status', 'proc_terminate'];

    /** @return list<array{name: string, passed: bool, required: bool, detail: string}> */
    public function run(): array
    {
        return [
            $this->row('php_version', version_compare(PHP_VERSION, '8.3.0', '>='), true, 'PHP '.PHP_VERSION.' at '.PHP_BINARY),
            $this->extensions(),
            $this->functions(),
            $this->binary('mysqldump'),
            $this->binary('mysql'),
            $this->database(),
            $this->driverTables(),
            $this->lock(),
            $this->row('queue_not_sync', config('queue.default') !== 'sync', true, 'Queue connection: '.config('queue.default')),
            $this->row('queue_runs_here', config('queue.default') !== 'database' || (bool) config('hosting.queue_from_scheduler'), true, 'A database queue needs QUEUE_FROM_SCHEDULER=true.'),
            $this->row('storage_writable', is_writable(storage_path()) && is_writable(base_path('bootstrap/cache')), true, 'storage and bootstrap/cache'),
            $this->row('public_storage_link', file_exists(public_path('storage')), false, 'public/storage (made with ln -s: PHP symlink() may be disabled)'),
            $this->row('app_key_set', (string) config('app.key') !== '', true, 'APP_KEY'),
            $this->row('debug_off', ! config('app.debug'), true, 'APP_DEBUG must be false.'),
            $this->row('backup_key_set', (string) config('backup.encryption_key') !== '', true, 'BACKUP_ENCRYPTION_KEY'),
            $this->row('rehearsal_database_set', (string) config('backup.rehearsal_database') !== '', false, 'BACKUP_REHEARSAL_DATABASE: without it a restore rehearsal needs CREATE DATABASE.'),
            $this->row('off_server_backups', config('backup.disk') !== 'local', false, 'Backup disk: '.config('backup.disk').'. A backup on this account is lost with this account.'),
        ];
    }

    /** @return array{name: string, passed: bool, required: bool, detail: string} */
    private function extensions(): array
    {
        $missing = array_values(array_filter(self::EXTENSIONS, fn (string $extension) => ! extension_loaded($extension)));

        return $this->row('php_extensions', $missing === [], true, $missing === [] ? 'All present.' : 'Switch on: '.implode(', ', $missing));
    }

    /** @return array{name: string, passed: bool, required: bool, detail: string} */
    private function functions(): array
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $blocked = array_values(array_intersect(self::FUNCTIONS, $disabled));

        return $this->row('process_functions', $blocked === [], true, $blocked === [] ? 'proc_open is available.' : 'Remove from disable_functions: '.implode(', ', $blocked));
    }

    /** @return array{name: string, passed: bool, required: bool, detail: string} */
    private function binary(string $name): array
    {
        $binary = (string) config("backup.binaries.{$name}", $name);

        try {
            $result = Process::timeout(20)->run([$binary, '--version']);

            return $this->row("{$name}_binary", $result->successful(), true, trim($result->output()) ?: "{$binary} did not run.");
        } catch (\Throwable) {
            return $this->row("{$name}_binary", false, true, "{$binary} could not be started.");
        }
    }

    /** @return array{name: string, passed: bool, required: bool, detail: string} */
    private function database(): array
    {
        try {
            $version = (string) DB::selectOne('SELECT VERSION() AS v')->v;
        } catch (\Throwable) {
            return $this->row('database', false, true, 'The database could not be reached.');
        }

        // The project is tested on MySQL 8. MariaDB is a different engine: it may run, but CI does not prove it.
        if (stripos($version, 'mariadb') !== false) {
            return $this->row('database', false, false, "{$version}: MariaDB, while the project is tested on MySQL 8. Run the test suite on this engine first.");
        }

        return $this->row('database', version_compare($version, '8.0.0', '>='), true, "MySQL {$version}");
    }

    /** @return array{name: string, passed: bool, required: bool, detail: string} */
    private function driverTables(): array
    {
        try {
            $missing = array_values(array_filter(['cache', 'cache_locks', 'sessions', 'jobs', 'failed_jobs'], fn (string $table) => ! Schema::hasTable($table)));
        } catch (\Throwable) {
            return $this->row('driver_tables', false, true, 'The database could not be reached.');
        }

        return $this->row('driver_tables', $missing === [], true, $missing === [] ? 'Present.' : 'Run the migrations; missing: '.implode(', ', $missing));
    }

    /** @return array{name: string, passed: bool, required: bool, detail: string} */
    private function lock(): array
    {
        try {
            $lock = Cache::lock('hosting:preflight', 5);
            $held = $lock->get();
            if ($held) {
                $lock->release();
            }

            return $this->row('cache_locks', (bool) $held, true, 'Cache store: '.config('cache.default'));
        } catch (\Throwable) {
            return $this->row('cache_locks', false, true, 'Cache store '.config('cache.default').' cannot hold a lock.');
        }
    }

    /** @return array{name: string, passed: bool, required: bool, detail: string} */
    private function row(string $name, bool $passed, bool $required, string $detail): array
    {
        return compact('name', 'passed', 'required', 'detail');
    }
}
