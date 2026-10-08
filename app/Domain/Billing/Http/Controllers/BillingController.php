<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http\Controllers;

use App\Domain\Billing\Exceptions\BillingActionRefusedException;
use App\Domain\Billing\Http\Resources\InvoiceResource;
use App\Domain\Billing\Models\BillingInterval;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceStatus;
use App\Domain\Billing\Policies\BillingPolicy;
use App\Domain\Billing\Services\InvoiceLedger;
use App\Domain\Billing\Services\SubscriptionBillingService;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Module 29 — a store's own billing: plan and period, upcoming charge,
 * invoices, and the changes an owner may make. The store is always the
 * server-resolved tenant; Invoice is tenant-scoped, so another store's
 * invoice is a 404 (ADR-001).
 */
final class BillingController
{
    public function show(Request $request, TenantContext $context, InvoiceLedger $ledger): JsonResponse
    {
        $this->authorize($request, 'view');
        $subscription = $this->subscription($context);
        $quote = $subscription->cancel_at_period_end ? null : $ledger->quoteNextPeriod($subscription);
        $open = Invoice::query()->where('status', InvoiceStatus::Open->value)->get();

        return response()->json(['data' => [
            'subscription' => [
                'status' => $subscription->status->value,
                'grants_access' => $subscription->status->grantsAccess(),
                'package' => ['code' => $subscription->package->code, 'name' => $subscription->package->name],
                'billing_interval' => $ledger->intervalOf($subscription)->value,
                'currency' => $subscription->currency ?? config('billing.currency'),
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'current_period_started_at' => $subscription->current_period_started_at?->toIso8601String(),
                'current_period_ends_at' => $subscription->current_period_ends_at?->toIso8601String(),
                'grace_period_ends_at' => $subscription->grace_period_ends_at?->toIso8601String(),
                'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
                // Phase B47: a downgrade waiting for the period end.
                'scheduled_package' => $subscription->scheduled_package_id === null ? null : (fn (?\App\Domain\Packages\Models\Package $p) => $p === null ? null : ['code' => $p->code, 'name' => $p->name])(\App\Domain\Packages\Models\Package::query()->find($subscription->scheduled_package_id)),
            ],
            'upcoming' => $quote === null ? null : [
                ...$quote,
                'period_start' => $quote['period_start']->toIso8601String(),
                'period_end' => $quote['period_end']->toIso8601String(),
            ],
            'balance' => [
                'open_invoices' => $open->count(),
                'overdue_invoices' => $open->filter(fn (Invoice $invoice) => $invoice->isOverdue())->count(),
                'amount_due_minor' => $open->sum(fn (Invoice $invoice) => $invoice->amountDue()),
                // Phase B47 (Module 29 §45): account credit, used on the next invoices first.
                'account_credit_minor' => app(\App\Domain\Billing\Services\AccountCredit::class)->balance($subscription->store_id, $subscription->currency ?? (string) config('billing.currency')),
            ],
        ]]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $this->authorize($request, 'view');
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(InvoiceStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $invoices = Invoice::query()
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('issued_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json(['data' => $invoices->through(fn (Invoice $invoice) => (new InvoiceResource($invoice))->resolve($request))]);
    }

    public function invoice(Request $request, Invoice $invoice): InvoiceResource
    {
        $this->authorize($request, 'view');

        return new InvoiceResource($invoice->load(['lines', 'payments', 'package']));
    }

    public function cancel(Request $request, TenantContext $context, SubscriptionBillingService $billing): JsonResponse
    {
        $this->authorize($request, 'manage');
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->respond(fn () => $billing->scheduleCancellation($this->subscription($context), $validated['reason'] ?? null));
    }

    public function resume(Request $request, TenantContext $context, SubscriptionBillingService $billing): JsonResponse
    {
        $this->authorize($request, 'manage');

        return $this->respond(fn () => $billing->resume($this->subscription($context)));
    }

    public function changeInterval(Request $request, TenantContext $context, SubscriptionBillingService $billing): JsonResponse
    {
        $this->authorize($request, 'manage');
        $validated = $request->validate(['billing_interval' => ['required', Rule::enum(BillingInterval::class)]]);

        return $this->respond(fn () => $billing->changeInterval($this->subscription($context), BillingInterval::from($validated['billing_interval'])));
    }

    /**
     * Phase B44 (owner decision 13): a store that may go live only after its
     * first payment asks for its first invoice now instead of at the end of
     * the trial. The invoice covers the first paid period (it starts when
     * the trial ends); Umar Techy records the payment, and the store can
     * launch. Asking again returns the same open invoice.
     */
    public function firstInvoice(Request $request, TenantContext $context, \App\Domain\Billing\Services\InvoiceLedger $ledger): JsonResponse
    {
        $this->authorize($request, 'manage');
        $subscription = $this->subscription($context);
        // Issuing writes the invoice and its outbox event together (ADR-004).
        $invoice = \Illuminate\Support\Facades\DB::transaction(fn () => $ledger->issueNextPeriod($subscription));
        if ($invoice === null) {
            return response()->json(['message' => 'Your package has no price yet. Contact the Umar Techy team.', 'code' => 'no_price'], 422);
        }

        return response()->json(['data' => new \App\Domain\Billing\Http\Resources\InvoiceResource($invoice)], 201);
    }

    /** @param callable(): Subscription $action */
    private function respond(callable $action): JsonResponse
    {
        try {
            $subscription = $action();
        } catch (BillingActionRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return response()->json(['data' => [
            'billing_interval' => $subscription->billing_interval->value,
            'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
            'current_period_ends_at' => $subscription->current_period_ends_at?->toIso8601String(),
        ]]);
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
