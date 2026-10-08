<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http\Resources;

use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Models\InvoicePayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Domain\Billing\Models\Invoice */
final class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'status' => $this->status->value,
            'billing_reason' => $this->billing_reason->value,
            'is_overdue' => $this->isOverdue(),
            'package' => $this->whenLoaded('package', fn () => ['code' => $this->package->code, 'name' => $this->package->name]),
            'currency' => $this->currency,
            'subtotal_minor' => $this->subtotal_minor,
            'tax_rate_bps' => $this->tax_rate_bps,
            'tax_label' => app(\App\Domain\Settings\Services\ConfigService::class)->get('billing.tax_label'), // Phase B47: a platform setting
            'tax_minor' => $this->tax_minor,
            'total_minor' => $this->total_minor,
            'amount_paid_minor' => $this->amount_paid_minor,
            // Phase B47: account credit used, and credit notes against it.
            'credit_applied_minor' => (int) $this->credit_applied_minor,
            'amount_credited_minor' => (int) $this->amount_credited_minor,
            'amount_due_minor' => $this->amountDue(),
            'bill_to' => [
                'store' => $this->bill_to['store'] ?? null,
                'email' => $this->bill_to['email'] ?? null,
            ],
            'period_start' => $this->period_start->toIso8601String(),
            'period_end' => $this->period_end->toIso8601String(),
            'issued_at' => $this->issued_at->toIso8601String(),
            'due_at' => $this->due_at->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (InvoiceLine $line) => [
                'kind' => $line->kind ?? 'charge', // Phase B47: a credit line is the unused part of the old plan
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_amount_minor' => $line->unit_amount_minor,
                'amount_minor' => $line->amount_minor,
            ])->all()),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn (InvoicePayment $payment) => (new InvoicePaymentResource($payment))->resolve($request))->all()),
            'store' => $this->whenLoaded('store', fn () => $this->store === null ? null : ['id' => $this->store->public_id, 'name' => $this->store->name]),
        ];
    }
}
