<?php

declare(strict_types=1);

namespace App\Domain\Payments\Http\Controllers;

use App\Domain\Orders\Models\Customer;
use App\Domain\Payments\Exceptions\OnlinePaymentRefusedException;
use App\Domain\Payments\Http\Resources\PaymentAttemptResource;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\PaymentStatus;
use App\Domain\Payments\Services\OnlinePaymentService;
use App\Domain\Settings\Services\ConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase B48 — Module 12 §41–42, §67: the shopper follows an online payment
 * and, when it failed or ran out of time, tries again — with the same or
 * the other wallet.
 *
 * Who may: the guest who checked out (the token handed out with the order;
 * only its hash is stored) or the signed-in customer whose order it is.
 * Anyone else gets 404, the same as a payment that does not exist (no
 * oracle). The store comes from the storefront's tenant context, so a
 * payment of another store is never found.
 */
final class StorefrontPaymentController
{
    public function __construct(private readonly OnlinePaymentService $online) {}

    public function show(Request $request, string $payment): JsonResponse
    {
        $model = $this->authorized($request, $payment);
        $latest = $model->attempts()->latest('id')->first();

        // While the shopper watches, ask the provider now and then instead of waiting for the scheduler.
        if ($latest !== null && $latest->isInFlight() && ($latest->last_checked_at === null || $latest->last_checked_at->lte(now()->subSeconds(15))) && $latest->created_at->lte(now()->subSeconds(10))) {
            $latest = $this->online->refresh($latest);
            $model->refresh();
        }

        return response()->json(['data' => $this->view($request, $model, $latest)]);
    }

    public function retry(Request $request, string $payment): JsonResponse
    {
        $model = $this->authorized($request, $payment);
        $data = $request->validate([
            'method' => ['required', Rule::in(PaymentMethod::walletValues())],
            'payer' => ['required', 'array'],
            'payer.mobile' => ['required', 'string', 'max:20'],
            'payer.cnic_last6' => ['nullable', 'string', 'max:10'],
        ]);

        try {
            $attempt = $this->online->startForOrder($model, PaymentMethod::from($data['method']), $data['payer']);
        } catch (OnlinePaymentRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], $e->status);
        }

        return response()->json(['data' => $this->view($request, $model->refresh(), $attempt)], 201);
    }

    private function authorized(Request $request, string $publicId): Payment
    {
        $payment = Payment::query()->with('order')->where('public_id', $publicId)->first();
        $customer = $request->user() instanceof Customer ? $request->user() : null;
        $token = $request->header('X-Payment-Token') ?? $request->input('token');
        $allowed = $payment !== null && $payment->method->walletProvider() !== null
            && ($this->online->tokenMatches($payment, is_string($token) ? $token : null)
                || ($customer !== null && $payment->customer_id !== null && $payment->customer_id === $customer->id));
        abort_unless($allowed, 404);

        return $payment;
    }

    /** @return array<string, mixed> */
    private function view(Request $request, Payment $payment, ?PaymentAttempt $latest): array
    {
        $window = (int) app(ConfigService::class)->get('payments.order_payment_minutes');
        $endsAt = $payment->created_at->copy()->addMinutes($window);
        $open = in_array($payment->status, [PaymentStatus::RequiresAction, PaymentStatus::Pending], true);

        return [
            'id' => $payment->public_id,
            'order_number' => $payment->order?->order_number,
            'method' => $payment->method->value,
            'status' => $payment->status->value,
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
            'pay_by' => $open ? $endsAt->toIso8601String() : null,
            // A new try is possible when nothing is waiting for an answer and time is left.
            'can_retry' => $open && $endsAt->isFuture() && ($latest === null || ! $latest->isInFlight())
                && $payment->attempts()->count() < OnlinePaymentService::MAX_ATTEMPTS,
            'options' => $open ? $this->online->walletOptions($payment->amount_minor, $payment->currency) : [],
            'attempt' => $latest !== null ? (new PaymentAttemptResource($latest))->toArray($request) : null,
        ];
    }
}
