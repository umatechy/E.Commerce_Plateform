<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Exceptions\BillingActionRefusedException;
use App\Domain\Billing\Models\CreditNote;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoicePaymentMethod;
use App\Domain\Billing\Models\InvoiceStatus;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Settings\Services\ConfigService;
use Illuminate\Support\Facades\DB;

/**
 * Phase B47 — Module 29 §42–43 (refunds, credit notes), §45 (account credit),
 * §92 (financial approval), §94 (corrections are compensating records).
 *
 * A credit note corrects an issued invoice without changing it:
 *
 *   reduce_balance  an open invoice: less is due (a balance of 0 settles it);
 *   refund          a paid invoice: money paid back (method and reference
 *                   of the transfer are recorded);
 *   account_credit  a paid invoice: kept as the store's credit, used on its
 *                   next invoices.
 *
 * Amounts include tax; the tax part is split in the invoice's own ratio.
 * Together, a paid invoice's refunds and credits never exceed what was paid
 * on it (money and account credit used), and an open invoice's reductions
 * never exceed what is due. Notes waiting for approval count already.
 *
 * Approval: at or above the platform setting billing.approval_threshold_minor
 * (0 = off) a note waits; another platform staff member must approve it
 * (four eyes). Issued notes are immutable and numbered CN-000001…
 */
final class CreditNoteService
{
    public function __construct(
        private readonly InvoiceNumberGenerator $numbers,
        private readonly InvoiceLedger $ledger,
        private readonly SubscriptionBillingEngine $engine,
        private readonly AccountCredit $credit,
        private readonly ConfigService $config,
        private readonly AuditLogger $audit,
        private readonly RecordsOutboxEvents $outbox,
    ) {}

    /**
     * @param array{amount_minor: int, reason: string, settlement: string, refund_method?: ?string, refund_reference?: ?string, idempotency_key: string} $data
     *
     * @throws BillingActionRefusedException
     */
    public function create(Invoice $invoice, array $data, User $requester): CreditNote
    {
        return DB::transaction(function () use ($invoice, $data, $requester) {
            $existing = CreditNote::query()->withoutTenantScope()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                if ($existing->invoice_id !== $invoice->id) {
                    throw new BillingActionRefusedException('idempotency_key_reused', 'This idempotency key was already used for another invoice.');
                }

                return $existing;
            }
            [, $invoice] = $this->lock($invoice);
            $amount = $data['amount_minor'];
            $settlement = $data['settlement'];
            if ($amount <= 0) {
                throw new BillingActionRefusedException('amount_invalid', 'The amount must be more than zero.', 422);
            }
            if (! in_array($settlement, CreditNote::SETTLEMENTS, true)) {
                throw new BillingActionRefusedException('settlement_invalid', 'Choose refund, account credit or reduce the balance.', 422);
            }
            $this->assertAvailable($invoice, $settlement, $amount);
            if ($settlement === CreditNote::REFUND && (empty($data['refund_method']) || InvoicePaymentMethod::tryFrom((string) $data['refund_method']) === null || trim((string) ($data['refund_reference'] ?? '')) === '')) {
                throw new BillingActionRefusedException('refund_details_missing', 'Say how the money was paid back and give its reference.', 422);
            }

            $gross = $invoice->subtotal_minor + $invoice->tax_minor;
            $tax = $gross > 0 ? intdiv(2 * $amount * $invoice->tax_minor + $gross, 2 * $gross) : 0;
            $note = CreditNote::query()->withoutTenantScope()->create([
                'store_id' => $invoice->store_id, 'invoice_id' => $invoice->id, 'status' => CreditNote::PENDING,
                'settlement' => $settlement, 'reason' => trim($data['reason']), 'currency' => $invoice->currency,
                'subtotal_minor' => $amount - $tax, 'tax_minor' => $tax, 'total_minor' => $amount,
                'lines' => [['description' => "Credit on invoice {$invoice->number}: ".trim($data['reason']), 'amount_minor' => $amount]],
                'refund_method' => $settlement === CreditNote::REFUND ? $data['refund_method'] : null,
                'refund_reference' => $settlement === CreditNote::REFUND ? trim((string) $data['refund_reference']) : null,
                'requested_by' => $requester->id, 'idempotency_key' => $data['idempotency_key'],
            ]);
            $this->audit->record('billing.credit_note_requested', ['invoice' => $invoice->number, 'total_minor' => $amount, 'settlement' => $settlement], $note, $invoice->store_id, $requester);

            $threshold = (int) $this->config->get('billing.approval_threshold_minor');
            if ($threshold > 0 && $amount >= $threshold) {
                return $note; // waits for a second person (Module 29 §92)
            }

            return $this->issue($note, $invoice, null);
        });
    }

    /** @throws BillingActionRefusedException */
    public function approve(CreditNote $note, User $approver): CreditNote
    {
        return DB::transaction(function () use ($note, $approver) {
            $note = CreditNote::query()->withoutTenantScope()->lockForUpdate()->findOrFail($note->id);
            $this->assertPending($note);
            if ($note->requested_by === $approver->id) {
                throw new BillingActionRefusedException('same_person', 'Another member of the Umar Techy team must approve this credit note.', 403);
            }
            [, $invoice] = $this->lock($note->invoice()->withoutGlobalScopes()->firstOrFail());

            return $this->issue($note, $invoice, $approver);
        });
    }

    /** @throws BillingActionRefusedException */
    public function reject(CreditNote $note, User $approver, string $reason): CreditNote
    {
        return DB::transaction(function () use ($note, $approver, $reason) {
            $note = CreditNote::query()->withoutTenantScope()->lockForUpdate()->findOrFail($note->id);
            $this->assertPending($note);
            $note->update(['status' => CreditNote::REJECTED, 'approved_by' => $approver->id, 'approved_at' => now(), 'rejection_reason' => trim($reason)]);
            $this->audit->record('billing.credit_note_rejected', ['reason' => $reason], $note, $note->store_id, $approver);

            return $note;
        });
    }

    private function issue(CreditNote $note, Invoice $invoice, ?User $approver): CreditNote
    {
        $note->update([
            'number' => $this->numbers->nextCreditNote(), 'status' => CreditNote::ISSUED, 'issued_at' => now(),
            'approved_by' => $approver?->id, 'approved_at' => $approver !== null ? now() : null,
        ]);
        $invoice->update(['amount_credited_minor' => (int) $invoice->amount_credited_minor + $note->total_minor]);

        if ($note->settlement === CreditNote::ACCOUNT_CREDIT) {
            $this->credit->add($invoice->store_id, $note->total_minor, $note->currency, 'credit_note', $note->id, "Credit note {$note->number}", $approver !== null ? $approver->id : $note->requested_by);
        }
        if ($note->settlement === CreditNote::REDUCE_BALANCE && $invoice->amountDue() === 0) {
            // Nothing left to pay: settled, and a store held back by it recovers at once.
            $this->ledger->markPaid($invoice);
            $this->engine->process(Subscription::query()->withoutTenantScope()->findOrFail($invoice->subscription_id));
        }

        $this->audit->record('billing.credit_note_issued', [
            'number' => $note->number, 'invoice' => $invoice->number, 'total_minor' => $note->total_minor, 'settlement' => $note->settlement,
            'refund_reference' => $note->refund_reference,
        ], $note, $invoice->store_id, $approver);
        $this->outbox->recordEventFor($invoice->store_id, 'billing.credit_note_issued', [
            'credit_note_id' => $note->id, 'number' => $note->number, 'invoice_id' => $invoice->id, 'total_minor' => $note->total_minor, 'currency' => $note->currency, 'settlement' => $note->settlement,
        ], "credit_note:{$note->id}:issued");

        return $note;
    }

    /** @throws BillingActionRefusedException */
    private function assertAvailable(Invoice $invoice, string $settlement, int $amount): void
    {
        $pending = (int) CreditNote::query()->withoutTenantScope()->where('invoice_id', $invoice->id)->where('status', CreditNote::PENDING)->sum('total_minor');
        if ($settlement === CreditNote::REDUCE_BALANCE) {
            if ($invoice->status !== InvoiceStatus::Open) {
                throw new BillingActionRefusedException('invoice_not_open', "Invoice {$invoice->number} is not open; a refund or account credit corrects a paid invoice.", 422);
            }
            $available = $invoice->amountDue() - $pending;
        } else {
            if ($invoice->status === InvoiceStatus::Void) {
                throw new BillingActionRefusedException('invoice_void', "Invoice {$invoice->number} is void.", 422);
            }
            $returned = (int) CreditNote::query()->withoutTenantScope()->where('invoice_id', $invoice->id)->where('status', CreditNote::ISSUED)
                ->whereIn('settlement', [CreditNote::REFUND, CreditNote::ACCOUNT_CREDIT])->sum('total_minor');
            // Money is refunded only up to the money paid; account credit may also give back credit that was used.
            $pool = $settlement === CreditNote::REFUND ? $invoice->amount_paid_minor : $invoice->amount_paid_minor + (int) $invoice->credit_applied_minor;
            $available = $pool - $returned - $pending;
        }
        if ($amount > $available) {
            throw new BillingActionRefusedException('amount_exceeds_available', 'At most '.max(0, $available)." can be credited on invoice {$invoice->number}.", 422);
        }
    }

    /** @throws BillingActionRefusedException */
    private function assertPending(CreditNote $note): void
    {
        if ($note->status !== CreditNote::PENDING) {
            throw new BillingActionRefusedException('credit_note_not_pending', 'This credit note is not waiting for approval.');
        }
    }

    /** @return array{0: Subscription, 1: Invoice} the same lock order as the billing run */
    private function lock(Invoice $invoice): array
    {
        $subscription = Subscription::query()->withoutTenantScope()->lockForUpdate()->findOrFail($invoice->subscription_id);

        return [$subscription, Invoice::query()->withoutTenantScope()->lockForUpdate()->findOrFail($invoice->id)];
    }
}
