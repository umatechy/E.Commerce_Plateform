<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\Billing\Exceptions\BillingActionRefusedException;
use App\Domain\Billing\Models\CreditNote;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoicePaymentMethod;
use App\Domain\Billing\Models\PaymentNotice;
use App\Domain\Billing\Services\BillingDocumentRenderer;
use App\Domain\Billing\Services\CreditNoteService;
use App\Domain\Billing\Services\PaymentNoticeService;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Phase B47 — Module 29 §42–43, §73–75, §79, §89, §92: Umar Techy's billing
 * desk — payments stores reported (confirm or reject), credit notes
 * (refund, account credit, lower balance) with a second person's approval
 * above the threshold, and every document as PDF. Platform staff only;
 * every change asks for step-up (routes) and is audited (services).
 */
final class SuperAdminBillingOperationsController
{
    public function notices(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in([PaymentNotice::PENDING, PaymentNotice::APPROVED, PaymentNotice::REJECTED])]])['status'] ?? PaymentNotice::PENDING;
        $rows = PaymentNotice::query()->withoutTenantScope()->with('invoice:id,number,total_minor,amount_paid_minor,amount_credited_minor,status')
            ->where('status', $status)->latest('id')->paginate(25);
        $stores = Store::query()->withTrashed()->whereIn('id', collect($rows->items())->pluck('store_id'))->pluck('name', 'id');

        return response()->json([
            'data' => collect($rows->items())->map(fn (PaymentNotice $n) => [
                'id' => $n->public_id, 'store' => ['id' => $n->store_id, 'name' => $stores[$n->store_id] ?? null],
                'invoice' => $n->invoice === null ? null : ['id' => $n->invoice->public_id, 'number' => $n->invoice->number, 'due_minor' => $n->invoice->amountDue()],
                'amount_minor' => $n->amount_minor, 'currency' => $n->currency, 'method' => $n->method, 'reference' => $n->reference,
                'paid_on' => $n->paid_on->toDateString(), 'note' => $n->note, 'status' => $n->status, 'rejection_reason' => $n->rejection_reason,
                'created_at' => $n->created_at->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total(), 'per_page' => $rows->perPage()],
            'pending' => PaymentNotice::query()->withoutTenantScope()->where('status', PaymentNotice::PENDING)->count(),
        ]);
    }

    public function approveNotice(Request $request, string $notice, PaymentNoticeService $notices): JsonResponse
    {
        return $this->refusable(fn () => ['status' => $notices->approve($this->findNotice($notice), $request->user())->status]);
    }

    public function rejectNotice(Request $request, string $notice, PaymentNoticeService $notices): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->refusable(fn () => ['status' => $notices->reject($this->findNotice($notice), $request->user(), $data['reason'])->status]);
    }

    public function creditNotes(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in([CreditNote::PENDING, CreditNote::ISSUED, CreditNote::REJECTED])]])['status'] ?? null;
        $rows = CreditNote::query()->withoutTenantScope()->with('invoice:id,number')->when($status, fn ($q, $s) => $q->where('status', $s))->latest('id')->paginate(25);
        $stores = Store::query()->withTrashed()->whereIn('id', collect($rows->items())->pluck('store_id'))->pluck('name', 'id');

        return response()->json([
            'data' => collect($rows->items())->map(fn (CreditNote $n) => [
                'id' => $n->public_id, 'number' => $n->number, 'status' => $n->status, 'settlement' => $n->settlement, 'reason' => $n->reason,
                'store' => ['id' => $n->store_id, 'name' => $stores[$n->store_id] ?? null], 'invoice' => $n->invoice?->number,
                'total_minor' => $n->total_minor, 'currency' => $n->currency, 'refund_method' => $n->refund_method, 'refund_reference' => $n->refund_reference,
                'requested_by' => $n->requested_by, 'approved_by' => $n->approved_by, 'rejection_reason' => $n->rejection_reason,
                'created_at' => $n->created_at->toIso8601String(), 'issued_at' => $n->issued_at?->toIso8601String(),
                'mine' => $n->requested_by === $request->user()->id,
            ])->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total(), 'per_page' => $rows->perPage()],
            'pending' => CreditNote::query()->withoutTenantScope()->where('status', CreditNote::PENDING)->count(),
        ]);
    }

    public function createCreditNote(Request $request, Invoice $invoice, CreditNoteService $notes): JsonResponse
    {
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
            'settlement' => ['required', Rule::in(CreditNote::SETTLEMENTS)],
            'refund_method' => ['nullable', Rule::enum(InvoicePaymentMethod::class)],
            'refund_reference' => ['nullable', 'string', 'max:128'],
        ]);
        // A retry with the same Idempotency-Key returns the same note (the page sends one per dialog).
        $data['idempotency_key'] = (string) ($request->header('Idempotency-Key') ?: \Illuminate\Support\Str::ulid());

        return $this->refusable(function () use ($notes, $invoice, $data, $request) {
            $note = $notes->create($invoice, $data, $request->user());

            return ['id' => $note->public_id, 'number' => $note->number, 'status' => $note->status, 'total_minor' => $note->total_minor];
        }, 201);
    }

    public function approveCreditNote(Request $request, string $creditNote, CreditNoteService $notes): JsonResponse
    {
        return $this->refusable(fn () => ['status' => $notes->approve($this->findCreditNote($creditNote), $request->user())->status]);
    }

    public function rejectCreditNote(Request $request, string $creditNote, CreditNoteService $notes): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->refusable(fn () => ['status' => $notes->reject($this->findCreditNote($creditNote), $request->user(), $data['reason'])->status]);
    }

    public function invoicePdf(Invoice $invoice, BillingDocumentRenderer $renderer): Response
    {
        return $this->pdf($renderer->invoice($invoice), "invoice-{$invoice->number}.pdf");
    }

    public function creditNotePdf(string $creditNote, BillingDocumentRenderer $renderer): Response
    {
        $note = $this->findCreditNote($creditNote);
        abort_unless($note->status === CreditNote::ISSUED, 404);

        return $this->pdf($renderer->creditNote($note), "credit-note-{$note->number}.pdf");
    }

    private function findNotice(string $publicId): PaymentNotice
    {
        return PaymentNotice::query()->withoutTenantScope()->where('public_id', $publicId)->first() ?? abort(404);
    }

    private function findCreditNote(string $publicId): CreditNote
    {
        return CreditNote::query()->withoutTenantScope()->where('public_id', $publicId)->first() ?? abort(404);
    }

    /** @param callable(): array<string, mixed> $action */
    private function refusable(callable $action, int $status = 200): JsonResponse
    {
        try {
            return response()->json(['data' => $action()], $status);
        } catch (BillingActionRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }
    }

    private function pdf(string $bytes, string $name): Response
    {
        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
