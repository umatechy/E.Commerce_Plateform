<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Services\DumpStrategies;

use App\Domain\DataProtection\Exceptions\BackupIntegrityException;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Runs the `mysqldump` and `mysql` binaries for the dump, restore and
 * rehearsal strategies (Module 23 §23).
 *
 * Credentials reach the binary only through a temporary
 * --defaults-extra-file that exists for the length of one call. They are
 * never a command-line argument (visible to other users in the process
 * list), never in a log line, and never in the error this class throws:
 * the binary's own error output is passed on with the password, if it
 * ever appeared, removed.
 *
 * Commands are argument lists, never a shell string, so a database name
 * or a file path cannot be read as shell syntax.
 */
final class MysqlClient
{
    /** @return array<string, mixed> the default connection's settings */
    public function connection(): array
    {
        return (array) config('database.connections.'.config('database.default'));
    }

    public function database(): string
    {
        return (string) $this->connection()['database'];
    }

    /**
     * @param 'mysqldump'|'mysql' $binary
     * @param list<string> $arguments
     * @throws BackupIntegrityException when the binary cannot run or exits non-zero
     */
    public function run(string $binary, array $arguments, string $failure): ProcessResult
    {
        $config = $this->connection();
        $credentialsFile = tempnam(sys_get_temp_dir(), 'mysql_cnf_') ?: throw new BackupIntegrityException('A working file could not be created.');

        file_put_contents($credentialsFile, sprintf(
            "[client]\nuser=\"%s\"\npassword=\"%s\"\nhost=\"%s\"\nport=%s\n",
            addcslashes((string) $config['username'], '"\\'),
            addcslashes((string) ($config['password'] ?? ''), '"\\'),
            addcslashes((string) $config['host'], '"\\'),
            (int) ($config['port'] ?? 3306),
        ));
        @chmod($credentialsFile, 0600);

        try {
            $result = Process::timeout((int) config('backup.process_timeout', 3600))->env($this->environment())->run([
                (string) config("backup.binaries.{$binary}", $binary),
                '--defaults-extra-file='.$credentialsFile,
                ...$arguments,
            ]);
        } catch (\Throwable $e) {
            throw new BackupIntegrityException("{$failure}: ".$this->scrub($e->getMessage(), $credentialsFile));
        } finally {
            // The credentials file must never survive this call, success or failure.
            @unlink($credentialsFile);
        }

        if (! $result->successful()) {
            throw new BackupIntegrityException("{$failure}: ".$this->scrub(trim($result->errorOutput()) ?: 'exit code '.$result->exitCode(), $credentialsFile));
        }

        return $result;
    }

    /**
     * Windows only: a child process cannot open a network socket without
     * SystemRoot (Winsock error 10106), and some PHP servers — `artisan
     * serve` among them — start PHP without it. Elsewhere nothing is added.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [];
        }

        $root = getenv('SystemRoot') ?: (string) ($_SERVER['SystemRoot'] ?? $_SERVER['WINDIR'] ?? 'C:\Windows');

        return ['SystemRoot' => $root];
    }

    /** A path the mysql client's `source` command accepts on every platform. */
    public static function sourcePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function scrub(string $message, string $credentialsFile): string
    {
        $password = (string) ($this->connection()['password'] ?? '');
        $message = str_replace($credentialsFile, '[credentials file]', $message);

        return $password === '' ? $message : str_replace($password, '[hidden]', $message);
    }
}
