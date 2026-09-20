<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Console;

use App\Domain\Marketing\Services\AbandonedCartDetectionService;
use Illuminate\Console\Command;

/** Mirrors App\Domain\Inventory\Console\ExpireStaleReservations's exact dispatcher pattern (Phase B4). */
final class DetectAbandonedCarts extends Command
{
    protected $signature = 'marketing:detect-abandoned-carts';

    protected $description = 'Detects newly-abandoned carts and emits marketing.abandoned_cart_detected outbox events (Module 15 §33-35).';

    public function handle(AbandonedCartDetectionService $service): int
    {
        $count = $service->detectAndNotify();
        $this->info("Detected {$count} newly-abandoned cart(s).");

        return self::SUCCESS;
    }
}
