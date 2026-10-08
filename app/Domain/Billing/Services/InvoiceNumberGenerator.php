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
        return $this->take(self::SEQUENCE, (string) config('billing.invoice_prefix'));
    }

    /** Phase B47 (Module 29 §43): credit notes have their own gap-free series, CN-000001. */
    public function nextCreditNote(): string
    {
        return $this->take('credit_note', 'CN-');
    }

    private function take(string $sequence, string $prefix): string
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Document numbers must be taken inside the transaction that issues the document.');
        }

        DB::table('billing_sequences')->insertOrIgnore(['key' => $sequence, 'next_value' => 1]);
        $value = (int) DB::table('billing_sequences')->where('key', $sequence)->lockForUpdate()->value('next_value');
        DB::table('billing_sequences')->where('key', $sequence)->update(['next_value' => $value + 1]);

        return $prefix.str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }
}
