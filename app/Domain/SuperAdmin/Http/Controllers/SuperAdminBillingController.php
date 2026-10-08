<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\Billing\Exceptions\BillingActionRefusedException;
use App\Domain\Billing\Http\Resources\InvoicePaymentResource;
use App\Domain\Billing\Http\Resources\InvoiceResource;
use App\Domain\Billing\Http\Resources\PackagePriceResource;
use App\Domain\Billing\Models\BillingInterval;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoicePaymentMethod;
use App\Domain\Billing\Models\InvoiceStatus;
use App\Domain\Billing\Models\PackagePrice;
use App\Domain\Billing\Services\InvoiceService;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Tenancy\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Module 29 — platform billing administration (super_admin.platform
 * group: platform context, every action audited by the group's
 * middleware, and the money-moving ones again here with their details).
 */
final class SuperAdminBillingController
{
    public function prices(): JsonResponse
    {
        $prices = PackagePrice::query()->with('package')->orderBy('package_id')->orderBy('billing_interval')->orderBy('currency')->get();

        return response()->json(['data' => PackagePriceResource::collection($prices)]);
    }

    /** Creates the price for (package, interval, currency), or changes its amount if it exists. */
    public function upsertPrice(Request $request): JsonResponse
    {
        if (is_string($request->input('currency'))) {
            $request->merge(['currency' => strtoupper(trim($request->input('currency')))]);
        }
        $validated = $request->validate([
            'package_code' => ['required', 'string', Rule::exists('packages', 'code')],
            'billing_interval' => ['required', Rule::enum(BillingInterval::class)],
            'currency' => ['required', 'string', 'size:3', \App\Domain\Settings\Services\Currencies::rule()], // owner decision 2026-10-03
            'amount_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $package = Package::query()->where('code', $validated['package_code'])->firstOrFail();
        $price = PackagePrice::query()->firstOrNew([
            'package_id' => $package->id,
            'billing_interval' => $validated['billing_interval'],
            'currency' => strtoupper($validated['currency']),
        ]);
        $previous = $price->exists ? $price->amount_minor : null;
        $price->fill([
            'amount_minor' => $validated['amount_minor'],
            'is_active' => $validated['is_active'] ?? ($price->exists ? $price->is_active : true),
        ])->save();

        app(AuditLogger::class)->record('super_admin.billing.price_saved', [
            'package' => $package->code, 'interval' => $price->billing_interval, 'currency' => $price->currency,
            'previous_amount_minor' => $previous, 'amount_minor' => $price->amount_minor, 'is_active' => $price->is_active,
        ], $price);

        return (new PackagePriceResource($price->load('package')))->response()->setStatusCode($previous === null ? 201 : 200);
    }

    public function updatePrice(Request $request, PackagePrice $price): PackagePriceResource
    {
        $validated = $request->validate([
            'amount_minor' => ['sometimes', 'integer', 'min:0', 'max:100000000000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $before = ['amount_minor' => $price->amount_minor, 'is_active' => $price->is_active];
        $price->update($validated);

        app(AuditLogger::class)->record('super_admin.billing.price_saved', [
            'before' => $before, 'after' => ['amount_minor' => $price->amount_minor, 'is_active' => $price->is_active],
        ], $price);

        return new PackagePriceResource($price->load('package'));
    }

    public function invoices(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(InvoiceStatus::class)],
            'store' => ['nullable', 'string', 'size:26'], // a store public_id
            'overdue' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $invoices = Invoice::query()
            ->with(['store' => fn ($q) => $q->withTrashed()->select(['id', 'public_id', 'name']), 'package'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['store'] ?? null, fn ($q, $store) => $q->where('store_id', Store::query()->withTrashed()->where('public_id', $store)->value('id') ?? 0))
            ->when($request->boolean('overdue'), fn ($q) => $q->where('status', InvoiceStatus::Open->value)->where('due_at', '<=', now()))
            ->orderByDesc('issued_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return response()->json(['data' => $invoices->through(fn (Invoice $invoice) => (new InvoiceResource($invoice))->resolve($request))]);
    }

    public function invoice(Invoice $invoice): InvoiceResource
    {
        return new InvoiceResource($invoice->load(['lines', 'payments', 'package', 'store' => fn ($q) => $q->withTrashed()]));
    }

    public function recordPayment(Request $request, Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        $validated = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::enum(InvoicePaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:128'],
            'note' => ['nullable', 'string', 'max:500'],
            'received_at' => ['nullable', 'date', 'before_or_equal:now'],
            // Required: a retried request must never record the money twice.
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128'],
        ]);

        try {
            $payment = $invoices->recordPayment($invoice, [
                'amount_minor' => (int) $validated['amount_minor'],
                'method' => InvoicePaymentMethod::from($validated['method']),
                'reference' => $validated['reference'] ?? null,
                'note' => $validated['note'] ?? null,
                'received_at' => isset($validated['received_at']) ? CarbonImmutable::parse($validated['received_at']) : CarbonImmutable::now(),
                'idempotency_key' => $validated['idempotency_key'],
            ], $request->user());
        } catch (BillingActionRefusedException $e) {
            return $this->refused($e);
        }

        return response()->json([
            'data' => [
                'payment' => (new InvoicePaymentResource($payment))->resolve($request),
                'invoice' => (new InvoiceResource($invoice->refresh()->load(['lines', 'payments', 'package'])))->resolve($request),
            ],
        ], $payment->wasRecentlyCreated ? 201 : 200);
    }

    public function void(Request $request, Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $invoice = $invoices->void($invoice, $validated['reason']);
        } catch (BillingActionRefusedException $e) {
            return $this->refused($e);
        }

        return (new InvoiceResource($invoice->load(['lines', 'payments', 'package'])))->response();
    }

    public function extendDueDate(Request $request, Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        $validated = $request->validate([
            'due_at' => ['required', 'date', 'after:now'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $invoice = $invoices->extendDueDate($invoice, CarbonImmutable::parse($validated['due_at']), $validated['reason']);
        } catch (BillingActionRefusedException $e) {
            return $this->refused($e);
        }

        return (new InvoiceResource($invoice->load(['lines', 'payments', 'package'])))->response();
    }

    /** Platform revenue at a glance, per currency (amounts are never summed across currencies). */
    public function summary(): JsonResponse
    {
        $open = Invoice::query()->where('status', InvoiceStatus::Open->value)
            ->selectRaw('currency, SUM(total_minor - amount_paid_minor - amount_credited_minor) AS outstanding, SUM(CASE WHEN due_at <= ? THEN total_minor - amount_paid_minor - amount_credited_minor ELSE 0 END) AS overdue, COUNT(*) AS invoices', [now()]) // B47: credit notes lower what is due
            ->groupBy('currency')->get()->keyBy('currency');

        $collected = DB::table('invoice_payments')->where('received_at', '>=', now()->startOfMonth())
            ->selectRaw('currency, SUM(amount_minor) AS collected')->groupBy('currency')->pluck('collected', 'currency');

        // Monthly recurring revenue of every paying subscription at its
        // current price; yearly prices count as 1/12 per month.
        $mrr = DB::table('subscriptions')
            ->join('package_prices', function ($join) {
                $join->on('package_prices.package_id', '=', 'subscriptions.package_id')
                    ->on('package_prices.billing_interval', '=', 'subscriptions.billing_interval')
                    ->whereRaw('package_prices.currency = COALESCE(subscriptions.currency, ?)', [config('billing.currency')]);
            })
            ->whereIn('subscriptions.status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value, SubscriptionStatus::GracePeriod->value])
            ->selectRaw("package_prices.currency AS currency, SUM(CASE WHEN package_prices.billing_interval = 'yearly' THEN package_prices.amount_minor / 12 ELSE package_prices.amount_minor END) AS mrr")
            ->groupBy('package_prices.currency')->pluck('mrr', 'currency');

        // Phase B47 (Module 29 §42): money paid back this month by credit notes.
        $refunded = DB::table('credit_notes')->where('status', 'issued')->where('settlement', 'refund')->where('issued_at', '>=', now()->startOfMonth())
            ->selectRaw('currency, SUM(total_minor) AS refunded')->groupBy('currency')->pluck('refunded', 'currency');

        $currencies = collect([...$open->keys(), ...$collected->keys(), ...$mrr->keys(), ...$refunded->keys()])->unique()->sort()->values();

        return response()->json(['data' => [
            'currencies' => $currencies->map(fn (string $currency) => [
                'currency' => $currency,
                'mrr_minor' => (int) round((float) ($mrr[$currency] ?? 0)),
                'outstanding_minor' => (int) ($open->get($currency)?->getAttribute('outstanding') ?? 0),
                'overdue_minor' => (int) ($open->get($currency)?->getAttribute('overdue') ?? 0),
                'open_invoices' => (int) ($open->get($currency)?->getAttribute('invoices') ?? 0),
                'collected_this_month_minor' => (int) ($collected[$currency] ?? 0),
                'refunded_this_month_minor' => (int) ($refunded[$currency] ?? 0),
            ])->all(),
            'subscriptions_by_status' => DB::table('subscriptions')->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n),
        ]]);
    }

    private function refused(BillingActionRefusedException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
    }
}
