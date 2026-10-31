<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Services\Rehearsal;

use App\Domain\DataProtection\Exceptions\BackupIntegrityException;
use App\Domain\DataProtection\Services\DumpStrategies\MysqlClient;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A restore rehearsal against real MySQL: the dump is imported into a
 * new, randomly named database on the same server, checked, and the
 * database is dropped. The live database is only read (its table list).
 *
 * The database account needs CREATE and DROP on `<database>_rehearsal_%`.
 * Without them the rehearsal fails with the server's own message; it
 * never reports success.
 *
 * The rehearsal database is created and dropped through the mysql
 * client, on its own connection: CREATE/DROP DATABASE on the
 * application's connection would commit a transaction it has open.
 *
 * Shared hosting lets no account create a database, so a rehearsal can
 * use one prepared by hand (BACKUP_REHEARSAL_DATABASE). Rehearsals destroy
 * what is in it, and the account may well reach other sites' databases, so
 * it is refused unless its name ends in "_rehearsal", it is not the live
 * database, and it is empty before the rehearsal starts.
 */
final class MysqlRehearsalTarget implements RehearsalTarget
{
    private const CONNECTION = 'backup_rehearsal';

    /** Tables the platform cannot run without. A restore missing one of them is not a usable restore. */
    private const CORE_TABLES = ['migrations', 'stores', 'users', 'store_user', 'roles', 'products', 'orders', 'order_items', 'customers', 'audit_logs'];

    public function __construct(private readonly MysqlClient $client) {}

    public function rehearse(string $plainSqlPath): array
    {
        // Letters, digits and underscore only: the name is put into SQL
        // as an identifier, so it is built here and never taken from input.
        $prepared = $this->preparedDatabase();
        $account = $this->account();
        $database = $prepared ?? Str::limit((string) preg_replace('/[^A-Za-z0-9_]/', '_', $this->client->database()), 40, '').'_rehearsal_'.Str::lower(Str::random(10));
        $created = false;
        $removed = false;

        try {
            if ($prepared !== null) {
                $this->assertEmpty($prepared, $account);
            } else {
                $this->client->run('mysql', ['-e', "CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"], 'the rehearsal database could not be created');
            }
            $created = true;

            $started = hrtime(true);
            $this->client->run('mysql', [$database, '-e', 'source '.MysqlClient::sourcePath($plainSqlPath)], 'the dump could not be imported', $account);
            $importMs = (int) ((hrtime(true) - $started) / 1_000_000);

            $started = hrtime(true);
            $this->useConnection($database, $account);
            $rehearsal = DB::connection(self::CONNECTION);

            try {
                [$checks, $counts] = $this->inspect($rehearsal, $database);
            } finally {
                DB::purge(self::CONNECTION);
            }

            $validateMs = (int) ((hrtime(true) - $started) / 1_000_000);
        } finally {
            if ($created) {
                try {
                    if ($prepared !== null) {
                        $this->emptyPrepared($prepared, $account);
                    } else {
                        $this->client->run('mysql', ['-e', "DROP DATABASE IF EXISTS `{$database}`"], 'the rehearsal database could not be dropped');
                    }
                    $removed = true;
                } catch (\Throwable) {
                    $removed = false; // reported below; never hides the original failure
                }
            }
        }

        $checks[] = ['name' => 'rehearsal_target_removed', 'passed' => $removed, 'detail' => $removed ? ($prepared !== null ? 'The rehearsal database was emptied.' : 'The rehearsal database was dropped.') : "The rehearsal database {$database} could not be cleared and must be cleared by hand."];

        return [
            'passed' => collect($checks)->every(fn (array $check) => $check['passed']),
            'checks' => $checks,
            'counts' => $counts,
            'import_ms' => $importMs,
            'validate_ms' => $validateMs,
            'target_removed' => $removed,
        ];
    }

    /** The database prepared by hand, if there is one and it is safe to destroy what is in it. */
    private function preparedDatabase(): ?string
    {
        $name = (string) config('backup.rehearsal_database', '');
        if ($name === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9_]+_rehearsal$/', $name) !== 1 || strcasecmp($name, $this->client->database()) === 0) {
            throw new BackupIntegrityException('BACKUP_REHEARSAL_DATABASE must be a separate database whose name ends in _rehearsal.');
        }

        return $name;
    }

    /** @return array<string, string> the account for a prepared database, when it has its own */
    private function account(): array
    {
        $username = (string) config('backup.rehearsal_username', '');

        return $username === '' ? [] : ['username' => $username, 'password' => (string) config('backup.rehearsal_password', '')];
    }

    /** @param array<string, string> $account */
    private function useConnection(string $database, array $account): void
    {
        config(['database.connections.'.self::CONNECTION => [...$this->client->connection(), ...$account, 'database' => $database]]);
    }

    /** @param array<string, string> $account */
    private function assertEmpty(string $database, array $account): void
    {
        $this->useConnection($database, $account);

        try {
            $found = $this->tables(DB::connection(self::CONNECTION), $database);
        } finally {
            DB::purge(self::CONNECTION);
        }

        if ($found !== []) {
            throw new BackupIntegrityException("The rehearsal database {$database} is not empty: it has ".count($found).' tables. Empty it by hand; nothing was changed.');
        }
    }

    /** @param array<string, string> $account */
    private function emptyPrepared(string $database, array $account): void
    {
        $this->useConnection($database, $account);

        try {
            $connection = DB::connection(self::CONNECTION);
            $connection->statement('SET FOREIGN_KEY_CHECKS=0');
            foreach ($this->tables($connection, $database) as $table) {
                $connection->statement('DROP TABLE `'.str_replace('`', '``', $table).'`');
            }
            if ($this->tables($connection, $database) !== []) {
                throw new BackupIntegrityException('Tables are left in the rehearsal database.');
            }
        } finally {
            DB::purge(self::CONNECTION);
        }
    }

    /**
     * @return array{0: list<array{name: string, passed: bool, detail: string}>, 1: array<string, int>}
     */
    private function inspect(ConnectionInterface $rehearsal, string $database): array
    {
        $checks = [];
        $tables = $this->tables($rehearsal, $database);

        // 1. Schema: the core tables exist and the migration history came with them.
        $missingCore = array_values(array_diff(self::CORE_TABLES, $tables));
        $checks[] = $this->check('core_tables_present', $missingCore === [], $missingCore === []
            ? count($tables).' tables restored, including every core table.'
            : 'Missing core tables: '.implode(', ', $missingCore).'.');

        $migrations = in_array('migrations', $tables, true) ? (int) $rehearsal->table('migrations')->count() : 0;
        $checks[] = $this->check('migration_history_present', $migrations > 0, "{$migrations} migrations recorded in the restored database.");

        // 2. Schema against the live database: a table that exists now but
        // not in the restore is expected only if it was added after the backup.
        $liveTables = $this->tables(DB::connection(), $this->client->database());
        $absent = array_values(array_diff($liveTables, $tables));
        $checks[] = $this->check('schema_matches_live', true, $absent === []
            ? 'The restored database has every table the live database has.'
            : count($absent).' tables exist live but not in this backup (added after it was taken): '.Str::limit(implode(', ', $absent), 200).'.');

        // 3. Representative data: row counts of the core tables.
        $counts = [];
        foreach (array_intersect(self::CORE_TABLES, $tables) as $table) {
            $counts[$table] = (int) $rehearsal->table($table)->count();
        }

        $foreignKeys = $rehearsal->select(
            'SELECT TABLE_NAME AS child, COLUMN_NAME AS child_column, REFERENCED_TABLE_NAME AS parent, REFERENCED_COLUMN_NAME AS parent_column
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$database],
        );

        // The names below go into SQL as identifiers. They come from the
        // restored schema, so anything that is not a plain identifier is
        // left out rather than quoted and trusted.
        $foreignKeys = array_values(array_filter($foreignKeys, fn (object $key) => collect([$key->child, $key->child_column, $key->parent, $key->parent_column])
            ->every(fn ($name) => preg_match('/^[A-Za-z0-9_]+$/', (string) $name) === 1)));

        // 4. Critical relationships: no row points at a parent that is not there.
        $orphans = [];
        foreach ($foreignKeys as $key) {
            $count = (int) $rehearsal->selectOne(
                "SELECT COUNT(*) AS n FROM `{$key->child}` c LEFT JOIN `{$key->parent}` p ON c.`{$key->child_column}` = p.`{$key->parent_column}`
                 WHERE c.`{$key->child_column}` IS NOT NULL AND p.`{$key->parent_column}` IS NULL",
            )->n;

            if ($count > 0) {
                $orphans[] = "{$key->child}.{$key->child_column} ({$count})";
            }
        }
        $checks[] = $this->check('relationships_intact', $orphans === [], $orphans === []
            ? count($foreignKeys).' foreign keys checked, no orphaned rows.'
            : 'Orphaned rows: '.Str::limit(implode(', ', $orphans), 300).'.');

        // 5. Tenant boundaries: a row and the row it belongs to are in the same store.
        $storeScoped = collect($rehearsal->select("SELECT TABLE_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'store_id'", [$database]))->pluck('name')->all();
        $crossTenant = [];
        $pairs = 0;
        foreach ($foreignKeys as $key) {
            if ($key->child === $key->parent || $key->parent === 'stores' || ! in_array($key->child, $storeScoped, true) || ! in_array($key->parent, $storeScoped, true)) {
                continue;
            }

            $pairs++;
            $count = (int) $rehearsal->selectOne(
                "SELECT COUNT(*) AS n FROM `{$key->child}` c JOIN `{$key->parent}` p ON c.`{$key->child_column}` = p.`{$key->parent_column}`
                 WHERE c.store_id IS NOT NULL AND p.store_id IS NOT NULL AND c.store_id <> p.store_id",
            )->n;

            if ($count > 0) {
                $crossTenant[] = "{$key->child} -> {$key->parent} ({$count})";
            }
        }
        $checks[] = $this->check('tenant_boundaries_intact', $crossTenant === [], $crossTenant === []
            ? "{$pairs} store-owned relationships checked, none crosses a store."
            : 'Rows linked across stores: '.Str::limit(implode(', ', $crossTenant), 300).'.');

        return [$checks, $counts];
    }

    /** @return list<string> */
    private function tables(ConnectionInterface $connection, string $database): array
    {
        return collect($connection->select("SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'", [$database]))
            ->pluck('name')->sort()->values()->all();
    }

    /** @return array{name: string, passed: bool, detail: string} */
    private function check(string $name, bool $passed, string $detail): array
    {
        return ['name' => $name, 'passed' => $passed, 'detail' => $detail];
    }
}
