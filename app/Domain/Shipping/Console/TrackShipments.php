<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Console;

use App\Domain\Shipping\Services\CourierShippingService;
use Illuminate\Console\Command;

/**
 * Phase B49 — Module 13 §54 "Tracking Polling": read the courier's
 * tracking for parcels whose turn has come (each parcel every 15 minutes,
 * backing off after failures, stopping when it is delivered or back).
 * Idempotent: an event already recorded is never recorded again.
 */
final class TrackShipments extends Command
{
    protected $signature = 'shipping:track';

    protected $description = 'Read couriers\' tracking for parcels on their way (Module 13 §54).';

    public function handle(CourierShippingService $couriers): int
    {
        $this->info('Read '.$couriers->pollDue().' parcel(s).');

        return self::SUCCESS;
    }
}
