<?php

declare(strict_types=1);

namespace App\Domain\Payments\Http\Controllers;

use App\Domain\Payments\Exceptions\RefundExceedsRefundableBalanceException;
use App\Domain\Payments\Http\Requests\ManualConfirmationRequest;
use App\Domain\Payments\Http\Requests\RefundRequest;
use App\Domain\Payments\Http\Resources\PaymentResource;
use App\Domain\Payments\Http\Resources\PaymentTransactionResource;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

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

        return PaymentResource::collection(Payment::query()->orderByDesc('created_at')->paginate(25));
    }

    public function show(Request $request, Payment $payment): PaymentResource
    {
        Gate::forUser($request->user())->authorize('view', $payment);

        return new PaymentResource($payment);
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
            reference: $request->string('reference'),
            notes: $request->input('notes'),
            actorId: $request->user()->id,
        );

        return (new PaymentTransactionResource($transaction))->response()->setStatusCode(201);
    }

    public function refund(RefundRequest $request, Payment $payment, PaymentService $payments): JsonResponse
    {
        Gate::forUser($request->user())->authorize('refund', $payment);

        try {
            $transaction = $payments->refund(
                $payment,
                amountMinor: (int) $request->input('amount_minor'),
                reason: $request->input('reason'),
                actorId: $request->user()->id,
                idempotencyKey: $request->string('idempotency_key'),
            );
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
