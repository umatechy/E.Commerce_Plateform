<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Shipping\Services\ShippingLabelService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\StoreDataMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Phase B51 (Module 03 §37 "safe asynchronous deletion … final
 * verification"; Module 30 §140) — removes one store's rows and files.
 *
 * - Refuses to run while any store_id table has no decision in
 *   StoreDataMap, so a new table is never purged or kept by accident.
 * - Order comes from the database's own foreign keys: a table is emptied
 *   before every table its rows point to (RESTRICT and CASCADE alike — a
 *   cascade from a parent could otherwise reach rows another table still
 *   protects), so the deletion never fails half-way on a constraint and
 *   never needs constraints switched off.
 * - Rows go in small batches, files before their rows. Running it again
 *   continues where it stopped: every step only removes what is left.
 * - Only rows with this store's id are touched (explicit where, no tenant
 *   scope involved); retained tables are never written.
 * - verify() counts what is left in every purged table: all must be zero.
 */
final class StorePurger
{
    private const BATCH = 500;

    /**
     * Rows that would be removed and rows that stay, per table.
     *
     * @return array{purge: array<string, int>, retain: array<string, int>}
     */
    public function plan(Store $store): array
    {
        $this->assertClassified();
        $purge = [];
        foreach ($this->orderedPurgeTables() as $table) {
            $purge[$table] = DB::table($table)->where('store_id', $store->id)->count();
        }
        $retain = [];
        foreach (StoreDataMap::RETAIN as $table) {
            if ($this->hasStoreId($table)) {
                $retain[$table] = DB::table($table)->where('store_id', $store->id)->count();
            }
        }

        return ['purge' => array_filter($purge), 'retain' => array_filter($retain)];
    }

    /**
     * @return array{rows: array<string, int>, files: int}
     */
    public function purge(Store $store): array
    {
        $this->assertClassified();
        // Counted first: rows removed by a cascade from another table are counted under their own table.
        $rows = array_filter($this->verify($store));
        $files = $this->removeFiles($store);
        foreach ($this->orderedPurgeTables() as $table) {
            do { // DELETE … LIMIT: small batches, also for tables without an id column
                $batch = DB::table($table)->where('store_id', $store->id)->limit(self::BATCH)->delete();
            } while ($batch === self::BATCH);
        }

        return ['rows' => $rows, 'files' => $files];
    }

    /** @return array<string, int> rows left per purged table (all zero after a complete purge) */
    public function verify(Store $store): array
    {
        $left = [];
        foreach ($this->orderedPurgeTables() as $table) {
            $left[$table] = DB::table($table)->where('store_id', $store->id)->count();
        }

        return $left;
    }

    /** @return list<string> store_id tables with no decision in StoreDataMap */
    public function unclassified(): array
    {
        $known = [...StoreDataMap::PURGE, ...StoreDataMap::RETAIN];

        return array_values(array_diff($this->storeIdTables(), $known));
    }

    private function assertClassified(): void
    {
        $missing = $this->unclassified();
        if ($missing !== []) {
            throw new \LogicException('These tables hold store rows but have no deletion decision in StoreDataMap: '.implode(', ', $missing));
        }
    }

    private function removeFiles(Store $store): int
    {
        $count = 0;
        $disks = ['local', (string) config('storefront.images.disk'), (string) config('returns.photos.disk'), ShippingLabelService::disk()];
        foreach (StoreDataMap::FILES as $table => $spec) {
            if (! $this->hasStoreId($table)) {
                continue;
            }
            [$diskColumn, $pathColumn] = $spec;
            $fixed = match ($spec[2] ?? null) {
                'labels' => ShippingLabelService::disk(),
                null => null,
                default => (string) $spec[2],
            };
            DB::table($table)->where('store_id', $store->id)->whereNotNull($pathColumn)
                ->select(array_values(array_filter(['id', $diskColumn, $pathColumn])))
                ->chunkById(self::BATCH, function ($rows) use ($diskColumn, $pathColumn, $fixed, &$count, &$disks) {
                    foreach ($rows as $row) {
                        $disk = $diskColumn !== null ? (string) $row->{$diskColumn} : (string) $fixed;
                        $disks[] = $disk;
                        if ($disk !== '' && Storage::disk($disk)->exists((string) $row->{$pathColumn})) {
                            Storage::disk($disk)->delete((string) $row->{$pathColumn});
                            $count++;
                        }
                    }
                });
        }
        foreach (array_unique(array_filter($disks)) as $disk) {
            foreach (StoreDataMap::folders($store->id, $store->public_id) as $folder) {
                if (Storage::disk($disk)->directoryExists($folder)) {
                    $count += count(Storage::disk($disk)->allFiles($folder));
                    Storage::disk($disk)->deleteDirectory($folder);
                }
            }
        }

        return $count;
    }

    /**
     * Purged tables, each before the purged tables it points to with a
     * RESTRICT, NO ACTION or CASCADE foreign key (found on a real run:
     * products cascade to inventories, which stock_movements protects).
     * SET NULL keys do not constrain the order; a self-reference is ignored.
     *
     * @return list<string>
     */
    private function orderedPurgeTables(): array
    {
        $tables = array_values(array_intersect(StoreDataMap::PURGE, $this->storeIdTables()));
        $set = array_flip($tables);
        $parentsOf = array_fill_keys($tables, []);
        $edges = DB::select(
            'SELECT k.table_name AS child, k.referenced_table_name AS parent FROM information_schema.key_column_usage k
             JOIN information_schema.referential_constraints rc ON rc.constraint_schema = k.table_schema AND rc.constraint_name = k.constraint_name AND rc.table_name = k.table_name
             WHERE k.table_schema = DATABASE() AND k.referenced_table_name IS NOT NULL AND rc.delete_rule IN (\'RESTRICT\', \'NO ACTION\', \'CASCADE\')'
        );
        foreach ($edges as $edge) {
            $child = (string) $edge->child;
            $parent = (string) $edge->parent;
            if ($child !== $parent && isset($set[$child], $set[$parent])) {
                $parentsOf[$child][$parent] = true;
            }
        }
        // Emit a table only when no remaining table still points to it (children first).
        $order = [];
        $remaining = $tables;
        while ($remaining !== []) {
            $progress = false;
            foreach ($remaining as $i => $table) {
                $hasChildLeft = false;
                foreach ($remaining as $other) {
                    if ($other !== $table && isset($parentsOf[$other][$table])) {
                        $hasChildLeft = true;
                        break;
                    }
                }
                if (! $hasChildLeft) {
                    $order[] = $table;
                    unset($remaining[$i]);
                    $progress = true;
                }
            }
            if (! $progress) {
                throw new \LogicException('The purge order has a cycle of foreign keys between: '.implode(', ', $remaining));
            }
        }

        return $order;
    }

    /** @return list<string> */
    private function storeIdTables(): array
    {
        static $cache = [];
        $database = (string) DB::connection()->getDatabaseName();

        return $cache[$database] ??= array_map(fn ($row) => (string) $row->t, DB::select(
            "SELECT DISTINCT table_name AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'store_id' ORDER BY 1"
        ));
    }

    private function hasStoreId(string $table): bool
    {
        return in_array($table, $this->storeIdTables(), true);
    }

}
