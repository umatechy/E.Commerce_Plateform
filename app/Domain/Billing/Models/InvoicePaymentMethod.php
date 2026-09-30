<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

/**
 * How a store paid the platform. Payments are recorded by a Super Admin
 * after the money arrived (no card gateway for platform billing yet).
 */
enum InvoicePaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Card = 'card';
    case MobileWallet = 'mobile_wallet';
    case Other = 'other';
}
