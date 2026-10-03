<?php

declare(strict_types=1);

namespace App\Domain\Returns\Models;

/** Module 13 §70 "Return shipping": how the goods come back. */
enum ReturnMethod: string
{
    case CustomerShips = 'customer_ships'; // the customer sends the parcel
    case CarrierPickup = 'carrier_pickup'; // a courier collects it
    case DropOff = 'drop_off'; // the customer brings it to the store
}
