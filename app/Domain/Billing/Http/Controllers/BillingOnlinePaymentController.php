<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http\Controllers;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceStatus;
use App\Domain\Billing\Policies\BillingPolicy;
use App\Domain\Payments\Exceptions\OnlinePaymentRefusedException;
use App\Domain\Payments\Http\Resources\PaymentAttemptResource;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Services\OnlinePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase B48 (gap G21's "card gateway"; Module 29 §73; Module 12 §92): a store
 * pays its Umar Techy invoice from a mobile wallet into Umar Techy's own
 * merchant account. The money is recorded on the invoice by
 * InvoiceService::recordPayment() only after the provider confirmed it —
 * the same single payment path as a confirmed payment notice.
 *
 * Route-model binding keeps another store's invoice a 404; paying needs
 * billing.manage.
 */
final class BillingOnlinePaymentController
{
    public function __construct(private readonly OnlinePaymentService $online) {}

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        abort_unless(app(BillingPolicy::class)->view($request->user()), 403);
        $latest = PaymentAttempt::query()->where('invoice_id', $invoice->id)->latest('id')->first();
        if ($latest !== null && $latest->isInFlight() && ($latest->last_checked_at === null || $latest->last_checked_at->lte(now()->subSeconds(15))) && $latest->created_at->lte(now()->subSeconds(10))) {
            $latest = $this->online->refresh($latest);
            $invoice->refresh();
        }
        $open = $invoice->status === InvoiceStatus::Open && $invoice->amountDue() > 0;

        return response()->json(['data' => [
            'invoice' => ['id' => $invoice->public_id, 'number' => $invoice->number, 'status' => $invoice->status->value, 'amount_due_minor' => $invoice->amountDue(), 'currency' => $invoice->currency],
            'options' => $open ? $this->online->invoiceOptions($invoice) : [],
            'can_pay' => $open && ($latest === null || ! $latest->isInFlight()),
            'attempt' => $latest !== null ? (new PaymentAttemptResource($latest))->toArray($request) : null,
        ]]);
    }

    public function start(Request $request, Invoice $invoice): JsonResponse
    {
        abort_unless(app(BillingPolicy::class)->manage($request->user()), 403);
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:32'],
            'payer' => ['required', 'array'],
            'payer.mobile' => ['required', 'string', 'max:20'],
            'payer.cnic_last6' => ['nullable', 'string', 'max:10'],
        ]);

        try {
            $attempt = $this->online->startForInvoice($invoice, $data['provider'], $data['payer'], $request->user());
        } catch (OnlinePaymentRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], $e->status);
        }

        return response()->json(['data' => (new PaymentAttemptResource($attempt))->toArray($request)], 201);
    }
}
