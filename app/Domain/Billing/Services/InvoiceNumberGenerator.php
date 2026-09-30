<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use Illuminate\Support\Facades\DB;

/**
 * Gap-free invoice numbers (INV-000001, ...). The counter row is locked
 * and advanced inside the caller's transaction, so a number is only
 * ever consumed by an invoice that was actually committed.
 */
final class InvoiceNumberGenerator
{
    private const SEQUENCE = 'invoice';

    public function next(): string
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Invoice numbers must be taken inside the transaction that issues the invoice.');
        }

        DB::table('billing_sequences')->insertOrIgnore(['key' => self::SEQUENCE, 'next_value' => 1]);
        $value = (int) DB::table('billing_sequences')->where('key', self::SEQUENCE)->lockForUpdate()->value('next_value');
        DB::table('billing_sequences')->where('key', self::SEQUENCE)->update(['next_value' => $value + 1]);

        return config('billing.invoice_prefix').str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }
}
