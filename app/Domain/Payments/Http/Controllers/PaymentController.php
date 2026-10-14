<?php

declare(strict_types=1);

namespace App\Domain\Payments\Http\Controllers;

use App\Domain\Payments\Exceptions\OnlinePaymentRefusedException;
use App\Domain\Payments\Exceptions\RefundExceedsRefundableBalanceException;
use App\Domain\Payments\Http\Resources\StaffPaymentAttemptResource;
use App\Domain\Payments\Models\PaymentReconciliationItem;
use App\Domain\Payments\Services\OnlinePaymentService;
use App\Domain\Payments\Http\Requests\ManualConfirmationRequest;
use App\Domain\Payments\Http\Requests\RefundRequest;
use App\Domain\Payments\Http\Resources\PaymentResource;
use App\Domain\Payments\Http\Resources\PaymentTransactionResource;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentStatus;
use App\Domain\Payments\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Staff-facing Payment API (Module 12 §68-69). Every method:
 * authenticated (staff.principal, via route group), authorized
 * (Gate::authorize below), tenant-scoped (BelongsToTenant + route-
 * model binding), validated where mutating.
 */
final class PaymentController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('viewAny', Payment::class);

        // Phase B31 (G6): each payment names its order; an order page lists its own.
        $filters = $request->validate([
            'order' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'attention' => ['nullable', 'boolean'], // Phase B48: flagged by reconciliation
        ]);

        return PaymentResource::collection(
            Payment::query()->with('order')
                ->when($filters['order'] ?? null, fn ($q, string $order) => $q->whereHas('order', fn ($o) => $o->where('public_id', $order)))
                ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
                ->when((bool) ($filters['attention'] ?? false), fn ($q) => $q->where('reconciliation_status', 'mismatch'))
                ->orderByDesc('created_at')->orderByDesc('id')
                ->paginate(25)
        );
    }

    public function show(Request $request, Payment $payment): PaymentResource
    {
        Gate::forUser($request->user())->authorize('view', $payment);

        $online = app(OnlinePaymentService::class);

        return (new PaymentResource($payment->load('order')))->additional(['meta' => [
            'paid_amount_minor' => $payment->paidAmountMinor(),
            'refunded_amount_minor' => $payment->refundedAmountMinor(),
            'refundable_amount_minor' => $payment->refundableAmountMinor(),
            // Phase B48: the online side — provider, every attempt, what reconciliation found,
            // and whether a refund goes through the provider's API or is made in its portal and recorded here.
            'provider' => $payment->provider,
            'provider_label' => $payment->provider !== null ? $online->providerLabel($payment->provider) : null,
            'refund_mode' => $payment->provider === null ? 'record' : ($online->providerRefundable($payment) ? 'provider' : 'portal'),
            'reconciliation_status' => $payment->reconciliation_status,
            'attempts' => StaffPaymentAttemptResource::collection($payment->attempts()->latest('id')->get())->toArray($request),
            'reconciliation' => $payment->reconciliation_status === null ? [] : PaymentReconciliationItem::query()->where('payment_id', $payment->id)->latest('id')->get()
                ->map(fn (PaymentReconciliationItem $item) => ['id' => $item->public_id, 'kind' => $item->kind, 'status' => $item->status, 'resolution_note' => $item->resolution_note, 'created_at' => $item->created_at->toIso8601String()])->all(),
        ]]);
    }

    public function transactions(Request $request, Payment $payment): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('view', $payment);

        return PaymentTransactionResource::collection(
            $payment->transactions()->orderByDesc('created_at')->get()
        );
    }

    /** Module 12 §63-64 "Manual Payment Confirmation / Cash Collection" — COD and Bank Transfer only. */
    public function manualConfirm(ManualConfirmationRequest $request, Payment $payment, PaymentService $payments): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', $payment);

        $transaction = $payments->recordManualConfirmation(
            $payment,
            amountMinor: (int) $request->input('amount_minor'),
            reference: $request->string('reference')->toString(),
            notes: $request->input('notes'),
            actorId: $request->user()->id,
        );

        return (new PaymentTransactionResource($transaction))->response()->setStatusCode(201);
    }

    public function refund(RefundRequest $request, Payment $payment, PaymentService $payments): JsonResponse
    {
        Gate::forUser($request->user())->authorize('refund', $payment);

        $online = app(OnlinePaymentService::class);
        try {
            // Phase B48: where the provider can refund by API, it does first and only its confirmation is recorded.
            $transaction = $online->providerRefundable($payment)
                ? $online->refundThroughProvider($payment, (int) $request->input('amount_minor'), $request->input('reason'), $request->user(), $request->string('idempotency_key')->toString())
                : $payments->refund(
                    $payment,
                    amountMinor: (int) $request->input('amount_minor'),
                    reason: $request->input('reason'),
                    actorId: $request->user()->id,
                    idempotencyKey: $request->string('idempotency_key')->toString(),
                    providerReference: $request->filled('provider_reference') ? $request->string('provider_reference')->toString() : null,
                );
        } catch (OnlinePaymentRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], $e->status);
        } catch (RefundExceedsRefundableBalanceException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'refund_exceeds_refundable_balance',
                'refundable_amount_minor' => $e->refundable,
            ], 422);
        }

        return (new PaymentTransactionResource($transaction))->response()->setStatusCode(201);
    }
}
