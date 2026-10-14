<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Services;

use App\Domain\Settings\Services\ConfigService;
use App\Domain\Shipping\Couriers\CourierRegistry;
use App\Domain\Shipping\Models\Shipment;

/**
 * Phase B49 (Module 13 §74): the link a customer follows to the courier's
 * own tracking page. No courier address is built in (none is invented):
 * the store sets its courier's link once, with {tracking} where the number
 * goes, on Shipping setup. Only for "another courier" — a connected courier
 * is tracked on the store's own order page.
 */
final class TrackingLink
{
    public static function for(Shipment $shipment): ?string
    {
        if ($shipment->carrier !== CourierRegistry::MANUAL || $shipment->tracking_number === null) {
            return null;
        }
        $template = app(ConfigService::class)->get('shipping.tracking_url_template');

        return is_string($template) && $template !== '' ? str_replace('{tracking}', rawurlencode($shipment->tracking_number), $template) : null;
    }
}
