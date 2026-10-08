<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\BillingCredit;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Compliance\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Phase B47 — Module 29 §45–46: a store's account credit with Umar Techy,
 * per currency. It comes from credit notes settled as credit and from the
 * unused part of a plan; it pays towards the store's next invoices first
 * (oldest credit first does not matter: credit has no expiry and is not
 * transferable or refundable as cash unless a credit note says so).
 *
 * Append-only ledger: rows are never changed; the balance is their sum.
 * Callers hold the subscription lock in a transaction (InvoiceLedger,
 * CreditNoteService), so two invoices cannot use the same credit.
 */
final class AccountCredit
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function balance(int $storeId, string $currency): int
    {
        return (int) BillingCredit::query()->withoutTenantScope()->where('store_id', $storeId)->where('currency', $currency)->sum('amount_minor');
    }

    public function add(int $storeId, int $amountMinor, string $currency, string $source, ?int $sourceId, ?string $note, ?int $actorId): BillingCredit
    {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('Account credit is added in positive amounts.');
        }
        $this->assertInTransaction();
        $credit = BillingCredit::query()->withoutTenantScope()->create([
            'store_id' => $storeId, 'amount_minor' => $amountMinor, 'currency' => $currency, 'source' => $source,
            'source_id' => $sourceId, 'note' => $note, 'created_by' => $actorId, 'created_at' => now(),
        ]);
        $this->audit->record('billing.credit_added', ['amount_minor' => $amountMinor, 'currency' => $currency, 'source' => $source], $credit, $storeId);

        return $credit;
    }

    /** Uses as much credit as there is, up to $maxMinor, on this invoice; returns the amount used. */
    public function useOn(Invoice $invoice, int $maxMinor): int
    {
        $this->assertInTransaction();
        $used = min($maxMinor, $this->balance($invoice->store_id, $invoice->currency));
        if ($used <= 0) {
            return 0;
        }
        BillingCredit::query()->withoutTenantScope()->create([
            'store_id' => $invoice->store_id, 'amount_minor' => -$used, 'currency' => $invoice->currency, 'source' => 'invoice',
            'source_id' => $invoice->id, 'invoice_id' => $invoice->id, 'note' => "Used on {$invoice->number}", 'created_at' => now(),
        ]);

        return $used;
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Account credit changes run inside the billing transaction.');
        }
    }
}
