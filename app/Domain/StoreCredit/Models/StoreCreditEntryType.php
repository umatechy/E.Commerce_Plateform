<?php

declare(strict_types=1);

namespace App\Domain\StoreCredit\Models;

/** Why a customer's store credit changed (Module 09 §52: every change is auditable). */
enum StoreCreditEntryType: string
{
    case ReturnRefund = 'return_refund'; // a return's refund, given as credit or paid back to the credit it came from
    case Adjustment = 'adjustment'; // given or taken back by staff, with a reason
    case Spent = 'spent'; // used on an order
    case OrderCancelled = 'order_cancelled'; // the order it was used on was cancelled
    case Erased = 'erased'; // the customer's personal data was erased; the balance ends
    case Expired = 'expired'; // Phase B35: credit that reached its expiry date (only when the store turned expiry on)
}
