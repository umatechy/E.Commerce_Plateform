<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Console;

use App\Domain\Inventory\Services\InventoryService;
use Illuminate\Console\Command;

/**
 * Module 08 §22 "Reservation Expiry" — same dispatcher-pattern
 * precedent as ADR-004's outbox:publish command (Phase B0).
 */
final class ExpireStaleReservations extends Command
{
    protected $signature = 'inventory:expire-reservations';

    protected $description = 'Release stock reservations past their expiry (Module 08 §22).';

    public function handle(InventoryService $inventory): int
    {
        $count = $inventory->expireStaleReservations();

        $this->info("Expired {$count} stale reservation(s).");

        return self::SUCCESS;
    }
}
