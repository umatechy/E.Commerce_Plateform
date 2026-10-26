<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Models;

/** Why a customer's points changed (Module 15 §45: an auditable ledger). */
enum LoyaltyEntryType: string
{
    case Earned = 'earned'; // an order qualified
    case RepeatBonus = 'repeat_bonus'; // every Nth order, when the store gives a bonus
    case Birthday = 'birthday'; // once a year, on the customer's birthday
    case Reversed = 'reversed'; // an earning order was refunded or cancelled
    case Redeemed = 'redeemed'; // turned into store credit by the customer
    case Adjustment = 'adjustment'; // given or taken back by staff, with a reason
    case Expired = 'expired'; // no activity for the store's expiry period
    case Erased = 'erased'; // the customer's personal data was erased; the balance ends
}
