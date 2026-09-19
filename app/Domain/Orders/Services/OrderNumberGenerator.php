<?php

declare(strict_types=1);

namespace App\Domain\Orders\Services;

use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Module 09 §5/§22 "Order Number Generation" — unique, collision-safe,
 * tenant-scoped, human-readable, stable. NOT the internal `id` (never
 * exposed) and NOT the platform-wide `public_id` ULID (every other
 * resource's API identifier) — a THIRD, distinct identifier Module 09
 * explicitly requires.
 *
 * CONCURRENCY: uses MySQL's `INSERT ... ON DUPLICATE KEY UPDATE ...
 * LAST_INSERT_ID(expr)` idiom — a single atomic statement that both
 * increments the per-store counter AND returns the new value, with no
 * read-then-write round-trip in PHP. This is the standard MySQL pattern
 * for a race-free auto-incrementing sequence and is consistent with
 * every other atomic-SQL mechanism already established in this codebase
 * (B2 UsageTrackingService, B4 InventoryService) rather than a new
 * strategy invented for this module.
 *
 * Sequential numbers are deliberately NOT treated as a security
 * boundary (Module 09 §22: "non-guessable enough... where necessary" —
 * order numbers are visible only to the store's own authenticated staff
 * and, in a future Module 10/11 customer-facing surface, only to the
 * order's own customer; guessing a sequential number never bypasses
 * ADR-001's tenant/ownership checks, which are the actual security
 * boundary here).
 */
final class OrderNumberGenerator
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Relies on `order_number_sequences` already having a row for this
     * store (seeded by StoreObserver at store-creation time, matching
     * the default-Warehouse/default-Roles precedent). This is required
     * for correctness, not just tidiness: `LAST_INSERT_ID(expr)` is only
     * evaluated on the `ON DUPLICATE KEY UPDATE` branch — if this
     * INSERT ever hit the fresh-row branch instead (no pre-existing
     * row), `LAST_INSERT_ID()` would NOT reliably reflect `next_number`,
     * since this table has no AUTO_INCREMENT column of its own. Always
     * pre-seeding the row removes that branch entirely; every real call
     * here always takes the UPDATE path.
     */
    public function next(): string
    {
        $storeId = $this->context->storeId();

        DB::statement(
            'INSERT INTO order_number_sequences (store_id, next_number) VALUES (?, 1) '.
            'ON DUPLICATE KEY UPDATE next_number = LAST_INSERT_ID(next_number + 1)',
            [$storeId]
        );

        $sequence = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;

        return sprintf('ORD-%06d', $sequence);
    }
}
