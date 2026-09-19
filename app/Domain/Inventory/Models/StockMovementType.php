<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

/**
 * Module 08 §31 "Stock Movement Types" — the module's own recommended
 * list, used verbatim so every future module (Orders, Purchasing,
 * Transfers, Returns) can post to the SAME ledger without a schema
 * change. B4 itself only ever creates OpeningBalance, AdjustmentIn,
 * AdjustmentOut, Reservation, ReservationRelease, and Correction — the
 * remaining cases are reserved for the modules that own those
 * workflows (see docs/development/b4-inspection-findings.md).
 */
enum StockMovementType: string
{
    case PurchaseIn = 'purchase_in';
    case SaleOut = 'sale_out';
    case ReturnIn = 'return_in';
    case RefundIn = 'refund_in';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case DamageOut = 'damage_out';
    case LossOut = 'loss_out';
    case OpeningBalance = 'opening_balance';
    case Correction = 'correction';
    case Reservation = 'reservation';
    case ReservationRelease = 'reservation_release';

    public function isInbound(): bool
    {
        return in_array($this, [
            self::PurchaseIn, self::ReturnIn, self::RefundIn, self::AdjustmentIn,
            self::TransferIn, self::OpeningBalance, self::ReservationRelease,
        ], true);
    }
}
