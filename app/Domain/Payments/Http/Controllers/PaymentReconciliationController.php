<?php

declare(strict_types=1);

namespace App\Domain\Payments\Http\Controllers;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Payments\Http\Resources\StaffPaymentAttemptResource;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Models\PaymentReconciliationItem;
use App\Domain\Payments\Providers\FailureCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Phase B48 — Module 12 §53–55, §93–94: what reconciliation found for this
 * store, and its resolution by a person with payments.reconcile.
 *
 * Resolving records a note and who resolved it; it moves no money. Money is
 * moved by the existing actions (refund, record a payment received), each
 * with its own permission and audit.
 */
final class PaymentReconciliationController
{
    public function index(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', Payment::class);
        $status = $request->validate(['status' => ['nullable', 'in:open,resolved']])['status'] ?? 'open';

        $items = PaymentReconciliationItem::query()->with(['attempt', 'payment.order'])
            ->where('status', $status)->orderByDesc('id')->paginate(25);

        return response()->json([
            'data' => $items->getCollection()->map(fn (PaymentReconciliationItem $item) => $this->present($request, $item))->all(),
            'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total(),
                'open_count' => PaymentReconciliationItem::query()->where('status', 'open')->count()],
        ]);
    }

    public function resolve(Request $request, string $item, AuditLogger $audit): JsonResponse
    {
        Gate::forUser($request->user())->authorize('reconcile', Payment::class);
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']]);

        $resolved = DB::transaction(function () use ($item, $data, $request, $audit) {
            $model = PaymentReconciliationItem::query()->where('public_id', $item)->lockForUpdate()->firstOrFail();
            if ($model->status !== 'open') {
                return null;
            }
            $model->update(['status' => 'resolved', 'resolution_note' => $data['note'], 'resolved_by' => $request->user()->id, 'resolved_at' => now()]);

            // An attempt held for a person (wrong amount, no answer) is closed, so the order can move on.
            $attempt = PaymentAttempt::query()->lockForUpdate()->find($model->payment_attempt_id);
            if ($attempt !== null && $attempt->status === PaymentAttempt::UNKNOWN) {
                $attempt->update(['status' => PaymentAttempt::EXPIRED, 'failure_category' => $attempt->failure_category ?? FailureCategory::UnknownProviderError->value, 'completed_at' => now()]);
            }
            if ($model->payment_id !== null && ! PaymentReconciliationItem::query()->where('payment_id', $model->payment_id)->where('status', 'open')->exists()) {
                Payment::query()->whereKey($model->payment_id)->update(['reconciliation_status' => 'resolved']);
            }
            $audit->record('payments.reconciliation_resolved', ['kind' => $model->kind, 'reference' => $attempt?->reference, 'note' => $data['note']], $model, $model->store_id, $request->user());

            return $model;
        });

        if ($resolved === null) {
            return response()->json(['message' => 'This item is already resolved.', 'code' => 'already_resolved'], 409);
        }

        return response()->json(['data' => $this->present($request, $resolved->fresh(['attempt', 'payment.order']))]);
    }

    /** @return array<string, mixed> */
    private function present(Request $request, PaymentReconciliationItem $item): array
    {
        return [
            'id' => $item->public_id,
            'kind' => $item->kind,
            'status' => $item->status,
            'details' => collect($item->details ?? [])->only(['expected_minor', 'expected_currency', 'confirmed_minor', 'confirmed_currency', 'reason', 'reference', 'checks'])->all(),
            'payment' => $item->payment === null ? null : ['id' => $item->payment->public_id, 'order' => $item->payment->order === null ? null : ['id' => $item->payment->order->public_id, 'order_number' => $item->payment->order->order_number]],
            'attempt' => $item->attempt === null ? null : (new StaffPaymentAttemptResource($item->attempt))->toArray($request),
            'resolution_note' => $item->resolution_note,
            'resolved_at' => $item->resolved_at?->toIso8601String(),
            'created_at' => $item->created_at->toIso8601String(),
        ];
    }
}
