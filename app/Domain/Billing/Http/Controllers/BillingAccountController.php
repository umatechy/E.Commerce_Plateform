<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http\Controllers;

use App\Domain\Billing\Exceptions\BillingActionRefusedException;
use App\Domain\Billing\Models\BillingCredit;
use App\Domain\Billing\Models\CreditNote;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PaymentNotice;
use App\Domain\Billing\Policies\BillingPolicy;
use App\Domain\Billing\Services\AccountCredit;
use App\Domain\Billing\Services\BillingDocumentRenderer;
use App\Domain\Billing\Services\InvoiceLedger;
use App\Domain\Billing\Services\PaymentNoticeService;
use App\Domain\Billing\Services\PlanChangeService;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Phase B47 — Module 29 §47–49, §45, §43, §73–75, §88 "billing portal": what
 * a store does with its own Umar Techy account — change its plan (with a
 * preview first), see its account credit and credit notes, download its
 * documents as PDF, and tell Umar Techy it paid an invoice.
 *
 * The store is the server-resolved tenant; invoices, credit notes and
 * notices are tenant-scoped (another store's are a 404). Looking needs
 * billing.view; changing needs billing.manage (the Owner, or whom they let).
 */
final class BillingAccountController
{
    /** The packages the store can move to: active, with a price in its interval and currency. */
    public function plans(Request $request, TenantContext $context, InvoiceLedger $ledger): JsonResponse
    {
        $this->authorize($request, 'view');
        $subscription = $this->subscription($context);

        return response()->json(['data' => Package::query()->where('is_active', true)->orderBy('id')->get()
            ->map(function (Package $p) use ($subscription, $ledger) {
                $price = $ledger->priceFor($subscription, null, $p->id);

                return $price === null && $p->id !== $subscription->package_id ? null : [
                    'code' => $p->code, 'name' => $p->name, 'price_minor' => $price?->amount_minor, 'currency' => $price?->currency,
                    'current' => $p->id === $subscription->package_id, 'scheduled' => $p->id === $subscription->scheduled_package_id,
                ];
            })->filter()->values()]);
    }

    public function preview(Request $request, TenantContext $context, PlanChangeService $plans): JsonResponse
    {
        $this->authorize($request, 'view');
        $data = $request->validate(['package' => ['required', 'string', 'max:64']]);

        return response()->json(['data' => $plans->preview($this->subscription($context), $this->package($data['package']))]);
    }

    public function change(Request $request, TenantContext $context, PlanChangeService $plans): JsonResponse
    {
        $this->authorize($request, 'manage');
        $data = $request->validate(['package' => ['required', 'string', 'max:64']]);
        try {
            $result = $plans->apply($this->subscription($context), $this->package($data['package']), $request->user(), 'owner');
        } catch (BillingActionRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return response()->json(['data' => [
            'timing' => $result['timing'], 'effective_at' => $result['effective_at'],
            'invoice' => $result['invoice'] === null ? null : ['id' => $result['invoice']->public_id, 'number' => $result['invoice']->number, 'total_minor' => $result['invoice']->total_minor, 'currency' => $result['invoice']->currency],
        ]]);
    }

    public function cancelChange(Request $request, TenantContext $context, PlanChangeService $plans): Response|JsonResponse
    {
        $this->authorize($request, 'manage');
        try {
            $plans->cancelScheduled($this->subscription($context), $request->user());
        } catch (BillingActionRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return response()->noContent();
    }

    public function credit(Request $request, TenantContext $context, AccountCredit $credit): JsonResponse
    {
        $this->authorize($request, 'view');
        $subscription = $this->subscription($context);
        $currency = $subscription->currency ?? (string) config('billing.currency');

        return response()->json(['data' => [
            'currency' => $currency,
            'balance_minor' => $credit->balance($subscription->store_id, $currency),
            'movements' => BillingCredit::query()->latest('id')->limit(50)->get()->map(fn (BillingCredit $c) => [
                'amount_minor' => $c->amount_minor, 'currency' => $c->currency, 'source' => $c->source, 'note' => $c->note, 'at' => $c->created_at->toIso8601String(),
            ]),
        ]]);
    }

    public function creditNotes(Request $request): JsonResponse
    {
        $this->authorize($request, 'view');

        return response()->json(['data' => CreditNote::query()->where('status', CreditNote::ISSUED)->with('invoice:id,number')->latest('id')->limit(50)->get()
            ->map(fn (CreditNote $n) => [
                'id' => $n->public_id, 'number' => $n->number, 'invoice' => $n->invoice?->number, 'settlement' => $n->settlement, 'reason' => $n->reason,
                'total_minor' => $n->total_minor, 'currency' => $n->currency, 'issued_at' => $n->issued_at?->toIso8601String(),
            ])]);
    }

    public function invoicePdf(Request $request, Invoice $invoice, BillingDocumentRenderer $renderer): Response
    {
        $this->authorize($request, 'view');

        return $this->pdf($renderer->invoice($invoice), "invoice-{$invoice->number}.pdf");
    }

    public function creditNotePdf(Request $request, CreditNote $creditNote, BillingDocumentRenderer $renderer): Response
    {
        $this->authorize($request, 'view');
        abort_unless($creditNote->status === CreditNote::ISSUED, 404);

        return $this->pdf($renderer->creditNote($creditNote), "credit-note-{$creditNote->number}.pdf");
    }

    public function notices(Request $request): JsonResponse
    {
        $this->authorize($request, 'view');

        return response()->json(['data' => PaymentNotice::query()->with('invoice:id,number')->latest('id')->limit(50)->get()->map(fn (PaymentNotice $n) => $this->notice($n))]);
    }

    public function submitNotice(Request $request, Invoice $invoice, PaymentNoticeService $notices): JsonResponse
    {
        $this->authorize($request, 'manage');
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::in(PaymentNoticeService::METHODS)],
            'reference' => ['required', 'string', 'max:128'],
            'paid_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $notice = $notices->submit($invoice, $data, $request->user());
        } catch (BillingActionRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return response()->json(['data' => $this->notice($notice->load('invoice:id,number'))], 201);
    }

    /** @return array<string, mixed> */
    private function notice(PaymentNotice $n): array
    {
        return [
            'id' => $n->public_id, 'invoice' => $n->invoice?->number, 'amount_minor' => $n->amount_minor, 'currency' => $n->currency,
            'method' => $n->method, 'reference' => $n->reference, 'paid_on' => $n->paid_on->toDateString(), 'note' => $n->note,
            'status' => $n->status, 'rejection_reason' => $n->rejection_reason, 'created_at' => $n->created_at->toIso8601String(),
        ];
    }

    private function pdf(string $bytes, string $name): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function package(string $code): Package
    {
        return Package::query()->where('code', $code)->first() ?? abort(404);
    }

    private function subscription(TenantContext $context): Subscription
    {
        return Subscription::query()->where('store_id', $context->storeId())->with('package')->firstOrFail();
    }

    private function authorize(Request $request, string $ability): void
    {
        abort_unless(app(BillingPolicy::class)->{$ability}($request->user()), 403);
    }
}
