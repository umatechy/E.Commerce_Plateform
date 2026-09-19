<?php

declare(strict_types=1);

namespace App\Domain\Payments\Models;

/**
 * Module 12 §3/§15/§18/§20-21. Only the methods B7 actually implements
 * an adapter for (see docs/development/b7-inspection-findings.md
 * "Scope Decision") — JazzCash/Easypaisa/cards are represented by the
 * generic `mock_redirect` gateway in test mode until real credentials
 * exist (Step 6's explicit allowance).
 */
enum PaymentMethod: string
{
    case CashOnDelivery = 'cod';
    case BankTransfer = 'bank_transfer';
    case MockRedirect = 'mock_redirect';
}
