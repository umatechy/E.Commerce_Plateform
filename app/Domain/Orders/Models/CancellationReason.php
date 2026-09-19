<?php

declare(strict_types=1);

namespace App\Domain\Orders\Models;

/** Module 09 §43 "Cancellation Reason" — the module's own example list. */
enum CancellationReason: string
{
    case CustomerRequest = 'customer_request';
    case OutOfStock = 'out_of_stock';
    case PaymentFailed = 'payment_failed';
    case AddressIssue = 'address_issue';
    case FraudReview = 'fraud_review';
    case StoreCancellation = 'store_cancellation';
    case Other = 'other';
}
