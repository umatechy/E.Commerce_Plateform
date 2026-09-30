<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Models\BillingInterval;
use App\Domain\Billing\Models\BillingReason;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceStatus;
use App\Domain\Billing\Models\PackagePrice;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Tenancy\Models\Store;
use Carbon\CarbonImmutable;

/**
 * Module 29 — creates and closes invoices. Every method expects the
 * caller to hold the subscription's row lock inside a transaction
 * (SubscriptionBillingEngine / InvoiceService do), so the invoice, the
 * subscription change, the audit entry and the outbox event commit
 * together (ADR-004).
 */
final class InvoiceLedger
{
    public function __construct(
        private readonly InvoiceNumberGenerator $numbers,
        private readonly RecordsOutboxEvents $outbox,
        private readonly BillingContact $contact,
    ) {}

    /** The invoice for the period starting at the subscription's current period end, if one was issued. */
    public function nextPeriodInvoice(Subscription $subscription): ?Invoice
    {
        return Invoice::query()->withoutTenantScope()
            ->where('subscription_id', $subscription->id)
            ->where('period_start', $subscription->current_period_ends_at)
            ->first();
    }

    public function priceFor(Subscription $subscription, ?BillingInterval $interval = null): ?PackagePrice
    {
        return PackagePrice::query()
            ->where('package_id', $subscription->package_id)
            ->where('billing_interval', ($interval ?? $this->intervalOf($subscription))->value)
            ->where('currency', $this->currencyOf($subscription))
            ->where('is_active', true)
            ->first();
    }

    /**
     * What the next period will cost, without issuing anything.
     *
     * @return ?array{period_start: CarbonImmutable, period_end: CarbonImmutable, currency: string, subtotal_minor: int, tax_rate_bps: int, tax_minor: int, total_minor: int}
     */
    public function quoteNextPeriod(Subscription $subscription): ?array
    {
        $price = $this->priceFor($subscription);

        if ($price === null || $subscription->current_period_ends_at === null) {
            return null;
        }

        $periodStart = CarbonImmutable::instance($subscription->current_period_ends_at);
        $taxRate = max(0, (int) config('billing.tax_rate_bps'));
        // Integer maths on minor units, rounded half up — never floats.
        $tax = intdiv($price->amount_minor * $taxRate + 5000, 10000);

        return [
            'period_start' => $periodStart,
            'period_end' => $this->intervalOf($subscription)->periodEnd($periodStart, $this->anchorOf($subscription, $periodStart)),
            'currency' => $price->currency,
            'subtotal_minor' => $price->amount_minor,
            'tax_rate_bps' => $taxRate,
            'tax_minor' => $tax,
            'total_minor' => $price->amount_minor + $tax,
        ];
    }

    /**
     * Issues the invoice for the next period, once: an existing invoice
     * for that period (in any status) is returned instead. Returns null
     * when the package has no active price in the subscription's
     * interval and currency — the platform never bills (or dunns) a
     * store for a price it forgot to set.
     */
    public function issueNextPeriod(Subscription $subscription): ?Invoice
    {
        if ($existing = $this->nextPeriodInvoice($subscription)) {
            return $existing;
        }

        $quote = $this->quoteNextPeriod($subscription);

        if ($quote === null) {
            return null;
        }

        $store = Store::query()->withTrashed()->findOrFail($subscription->store_id);
        $owner = $this->contact->ownerOf($store->id);
        $package = $subscription->package()->firstOrFail();
        $isFree = $quote['total_minor'] === 0;
        // The previous period was not billed (trial, or a restart after
        // cancellation/expiry), so this invoice starts paid service.
        $reason = Invoice::query()->withoutTenantScope()
            ->where('subscription_id', $subscription->id)
            ->where('period_end', $quote['period_start'])
            ->exists() ? BillingReason::SubscriptionCycle : BillingReason::SubscriptionStart;

        $invoice = Invoice::query()->withoutTenantScope()->create([
            'number' => $this->numbers->next(),
            'store_id' => $store->id,
            'subscription_id' => $subscription->id,
            'package_id' => $package->id,
            'status' => $isFree ? InvoiceStatus::Paid : InvoiceStatus::Open,
            'billing_reason' => $reason,
            'currency' => $quote['currency'],
            'subtotal_minor' => $quote['subtotal_minor'],
            'tax_rate_bps' => $quote['tax_rate_bps'],
            'tax_minor' => $quote['tax_minor'],
            'total_minor' => $quote['total_minor'],
            'amount_paid_minor' => 0,
            'bill_to' => ['store' => $store->name, 'store_id' => $store->public_id, 'email' => $owner?->email],
            'period_start' => $quote['period_start'],
            'period_end' => $quote['period_end'],
            'issued_at' => now(),
            // Due when the period starts; an invoice issued late (the
            // period already started) is due now, never in the past.
            'due_at' => $quote['period_start']->max(now()),
            'paid_at' => $isFree ? now() : null,
        ]);

        $invoice->lines()->create([
            'description' => sprintf('%s plan (%s), %s to %s', $package->name, $this->intervalOf($subscription)->value,
                $quote['period_start']->toDateString(), $quote['period_end']->toDateString()),
            'quantity' => 1,
            'unit_amount_minor' => $quote['subtotal_minor'],
            'amount_minor' => $quote['subtotal_minor'],
            'period_start' => $quote['period_start'],
            'period_end' => $quote['period_end'],
        ]);

        app(AuditLogger::class)->record('billing.invoice_issued', [
            'number' => $invoice->number,
            'total_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
            'period_start' => $invoice->period_start,
            'period_end' => $invoice->period_end,
        ], $invoice, $store->id);

        $this->outbox->recordEventFor($store->id, 'billing.invoice_issued', $this->payload($invoice), "invoice:{$invoice->id}:issued");

        return $invoice;
    }

    public function markVoid(Invoice $invoice, string $reason): void
    {
        $invoice->update(['status' => InvoiceStatus::Void, 'voided_at' => now(), 'void_reason' => $reason]);

        app(AuditLogger::class)->record('billing.invoice_voided', [
            'number' => $invoice->number, 'reason' => $reason, 'amount_paid_minor' => $invoice->amount_paid_minor,
        ], $invoice, $invoice->store_id);

        $this->outbox->recordEventFor($invoice->store_id, 'billing.invoice_voided', $this->payload($invoice), "invoice:{$invoice->id}:voided");
    }

    public function markPaid(Invoice $invoice): void
    {
        $invoice->update(['status' => InvoiceStatus::Paid, 'paid_at' => now()]);

        $this->outbox->recordEventFor($invoice->store_id, 'billing.invoice_paid', $this->payload($invoice), "invoice:{$invoice->id}:paid");
    }

    public function markUncollectible(Invoice $invoice): void
    {
        $invoice->update(['status' => InvoiceStatus::Uncollectible]);

        app(AuditLogger::class)->record('billing.invoice_uncollectible', ['number' => $invoice->number], $invoice, $invoice->store_id);
    }

    /** @return array<string, mixed> */
    public function payload(Invoice $invoice): array
    {
        return [
            'invoice_id' => $invoice->id,
            'invoice_public_id' => $invoice->public_id,
            'number' => $invoice->number,
            'status' => $invoice->status->value,
            'total_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
            'due_at' => $invoice->due_at->toIso8601String(),
        ];
    }

    public function intervalOf(Subscription $subscription): BillingInterval
    {
        return $subscription->billing_interval ?? BillingInterval::Monthly;
    }

    private function currencyOf(Subscription $subscription): string
    {
        return $subscription->currency ?? (string) config('billing.currency');
    }

    private function anchorOf(Subscription $subscription, CarbonImmutable $fallback): CarbonImmutable
    {
        return $subscription->billing_anchor_at !== null ? CarbonImmutable::instance($subscription->billing_anchor_at) : $fallback;
    }
}
