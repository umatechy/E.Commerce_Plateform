<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Exceptions\BillingActionRefusedException;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoicePaymentMethod;
use App\Domain\Billing\Models\InvoiceStatus;
use App\Domain\Billing\Models\PaymentNotice;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase B47 — Module 29 §73–74 (bank transfer, manual payment), §79
 * (payments to confirm), §92 (manual payment confirmation by staff): a
 * store says it paid an invoice — how, when, how much, the transfer
 * reference. It is only a notice: the invoice is paid when Umar Techy staff
 * approve it, which records the payment through InvoiceService (the one
 * place payments are recorded; idempotent per notice). A rejected notice
 * gives the store the reason.
 *
 * Until live payment gateways exist (B48), this is how a Pakistani store
 * pays its subscription by bank transfer, Easypaisa or JazzCash.
 */
final class PaymentNoticeService
{
    /** Methods a store may report (card payments come with a gateway, B48). */
    public const METHODS = ['bank_transfer', 'mobile_wallet', 'cash', 'other'];

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly AuditLogger $audit,
        private readonly RecordsOutboxEvents $outbox,
    ) {}

    /**
     * @param array{amount_minor: int, method: string, reference: string, paid_on: string, note?: ?string} $data
     *
     * @throws BillingActionRefusedException
     */
    public function submit(Invoice $invoice, array $data, User $by): PaymentNotice
    {
        return DB::transaction(function () use ($invoice, $data, $by) {
            $invoice = Invoice::query()->withoutTenantScope()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== InvoiceStatus::Open) {
                throw new BillingActionRefusedException('invoice_not_open', "Invoice {$invoice->number} has nothing left to pay.", 422);
            }
            $pending = (int) PaymentNotice::query()->withoutTenantScope()->where('invoice_id', $invoice->id)->where('status', PaymentNotice::PENDING)->sum('amount_minor');
            if ($data['amount_minor'] <= 0 || $data['amount_minor'] > $invoice->amountDue() - $pending) {
                throw new BillingActionRefusedException('amount_exceeds_balance', 'The amount is more than what is still due on this invoice (counting payments you already reported).', 422);
            }
            if (CarbonImmutable::parse($data['paid_on'])->isFuture()) {
                throw new BillingActionRefusedException('paid_on_future', 'The payment date cannot be in the future.', 422);
            }
            $notice = PaymentNotice::query()->withoutTenantScope()->create([
                'store_id' => $invoice->store_id, 'invoice_id' => $invoice->id, 'amount_minor' => $data['amount_minor'], 'currency' => $invoice->currency,
                'method' => $data['method'], 'reference' => trim($data['reference']), 'paid_on' => $data['paid_on'],
                'note' => isset($data['note']) && trim((string) $data['note']) !== '' ? trim((string) $data['note']) : null,
                'status' => PaymentNotice::PENDING, 'submitted_by' => $by->id,
            ]);
            $this->audit->record('billing.payment_notice_submitted', ['invoice' => $invoice->number, 'amount_minor' => $notice->amount_minor, 'method' => $notice->method, 'reference' => $notice->reference], $notice, $invoice->store_id, $by);
            $this->outbox->recordEventFor($invoice->store_id, 'billing.payment_notice_submitted', ['notice_id' => $notice->id, 'invoice_id' => $invoice->id, 'amount_minor' => $notice->amount_minor], "payment_notice:{$notice->id}:submitted");

            return $notice;
        });
    }

    /** @throws BillingActionRefusedException */
    public function approve(PaymentNotice $notice, User $staff): PaymentNotice
    {
        return DB::transaction(function () use ($notice, $staff) {
            $notice = PaymentNotice::query()->withoutTenantScope()->lockForUpdate()->findOrFail($notice->id);
            $this->assertPending($notice);
            $invoice = Invoice::query()->withoutTenantScope()->findOrFail($notice->invoice_id);
            $payment = $this->invoices->recordPayment($invoice, [
                'amount_minor' => $notice->amount_minor,
                'method' => InvoicePaymentMethod::from($notice->method),
                'reference' => $notice->reference,
                'note' => 'Confirmed from the store\'s payment notice'.($notice->note ? ": {$notice->note}" : ''),
                'received_at' => CarbonImmutable::instance($notice->paid_on),
                'idempotency_key' => "payment-notice:{$notice->public_id}",
            ], $staff);
            $notice->update(['status' => PaymentNotice::APPROVED, 'reviewed_by' => $staff->id, 'reviewed_at' => now(), 'invoice_payment_id' => $payment->id]);
            $this->audit->record('billing.payment_notice_approved', ['invoice' => $invoice->number, 'amount_minor' => $notice->amount_minor], $notice, $notice->store_id, $staff);

            return $notice;
        });
    }

    /** @throws BillingActionRefusedException */
    public function reject(PaymentNotice $notice, User $staff, string $reason): PaymentNotice
    {
        return DB::transaction(function () use ($notice, $staff, $reason) {
            $notice = PaymentNotice::query()->withoutTenantScope()->lockForUpdate()->findOrFail($notice->id);
            $this->assertPending($notice);
            $notice->update(['status' => PaymentNotice::REJECTED, 'reviewed_by' => $staff->id, 'reviewed_at' => now(), 'rejection_reason' => trim($reason)]);
            $this->audit->record('billing.payment_notice_rejected', ['reason' => $reason], $notice, $notice->store_id, $staff);
            $this->outbox->recordEventFor($notice->store_id, 'billing.payment_notice_rejected', ['notice_id' => $notice->id, 'invoice_id' => $notice->invoice_id], "payment_notice:{$notice->id}:rejected");

            return $notice;
        });
    }

    /** @throws BillingActionRefusedException */
    private function assertPending(PaymentNotice $notice): void
    {
        if ($notice->status !== PaymentNotice::PENDING) {
            throw new BillingActionRefusedException('notice_not_pending', 'This payment notice was already reviewed.');
        }
    }
}
