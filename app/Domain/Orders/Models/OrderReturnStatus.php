<?php

declare(strict_types=1);

namespace App\Domain\Orders\Models;

/**
 * Module 09 §15/§47 — the order's summary of its returns, separate from
 * OrderStatus, PaymentStatus and FulfillmentStatus (§16–17), and written
 * only by OrderService::syncReturnStatus(). The returns themselves live
 * in Module 09's return records (App\Domain\Returns).
 */
enum OrderReturnStatus: string
{
    case None = 'none';
    case Requested = 'return_requested'; // a return is open, nothing accepted yet
    case PartiallyReturned = 'partially_returned'; // some of what was ordered came back and was accepted
    case Returned = 'returned'; // everything ordered came back and was accepted
}
