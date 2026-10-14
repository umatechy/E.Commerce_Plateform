<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Payments\Exceptions\OnlinePaymentRefusedException;
use App\Domain\Payments\Http\Resources\StaffPaymentAttemptResource;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Models\PaymentProviderAccount;
use App\Domain\Payments\Models\PaymentReconciliationItem;
use App\Domain\Payments\Models\PlatformPaymentAccount;
use App\Domain\Payments\Providers\ProviderRegistry;
use App\Domain\Payments\Services\ProviderAccountService;
use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase B48 — Module 30 §26–27 "Payment administration / Provider
 * management"; Module 12 §66, §86, §89, §93: Umar Techy's view of payment
 * providers across the platform.
 *
 * - the capability matrix as built, and per provider whether stores may
 *   take real money with it (`payments.live_providers`) — opened only after
 *   the integration was checked in the provider's sandbox;
 * - Umar Techy's own merchant accounts, for subscription invoices;
 * - health: attempts of the last 24 hours by outcome, stores connected;
 * - lookup of an attempt by reference, and every store's open findings.
 *
 * Read-only towards stores' money (Module 30 §81: payment changes go
 * through Module 12, which the stores' own staff act on). Never returns a
 * credential. Changes ask for step-up (routes) and are audited.
 */
final class SuperAdminPaymentProviderController
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly ProviderAccountService $accounts,
        private readonly ConfigService $config,
    ) {}

    public function index(): JsonResponse
    {
        $platform = PlatformPaymentAccount::query()->get()->keyBy('provider');
        $since = now()->subDay();
        $stats = PaymentAttempt::query()->withoutTenantScope()->where('created_at', '>=', $since)->where('purpose', '!=', PaymentAttempt::TEST)
            ->selectRaw('provider, status, count(*) as n')->groupBy('provider', 'status')->get()
            ->groupBy('provider')->map(fn ($rows) => $rows->pluck('n', 'status')->map(fn ($n) => (int) $n));
        $connected = PaymentProviderAccount::query()->withoutTenantScope()
            ->selectRaw('provider, environment, is_enabled, count(*) as n')->groupBy('provider', 'environment', 'is_enabled')->get();

        return response()->json(['data' => [
            'providers' => array_values(array_map(function ($adapter) use ($platform, $stats, $connected) {
                $key = $adapter->key();
                $rows = $connected->where('provider', $key);

                return [
                    ...$this->accounts->describe($adapter, $platform->get($key)),
                    'stores' => [
                        'sandbox' => (int) $rows->where('environment', 'sandbox')->sum('n'),
                        'production' => (int) $rows->where('environment', 'production')->sum('n'),
                        'taking_payments' => (int) $rows->where('is_enabled', true)->sum('n'),
                    ],
                    'last_24h' => [
                        'succeeded' => $stats[$key]['succeeded'] ?? 0,
                        'failed' => $stats[$key]['failed'] ?? 0,
                        'expired' => $stats[$key]['expired'] ?? 0,
                        'pending' => ($stats[$key]['pending'] ?? 0),
                        'unknown' => $stats[$key]['unknown'] ?? 0,
                    ],
                ];
            }, $this->providers->all())),
            'simulator_enabled' => ProviderRegistry::simulatorEnabled(),
            'open_findings' => PaymentReconciliationItem::query()->withoutTenantScope()->where('status', 'open')->count(),
            'card' => ['available' => false],
        ]]);
    }

    /** Open or close a provider for real (production) money on the whole platform. */
    public function setLive(Request $request, string $provider, AuditLogger $audit): JsonResponse
    {
        abort_unless(in_array($provider, ProviderRegistry::REAL_PROVIDERS, true), 404);
        $allowed = $request->validate(['allowed' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:5', 'max:300']]);
        $current = (array) $this->config->get('payments.live_providers');
        $next = $allowed['allowed'] ? array_values(array_unique([...$current, $provider])) : array_values(array_diff($current, [$provider]));
        $this->config->set('payments.live_providers', $next, SettingScope::Platform, $request->user()->id, $allowed['reason']);

        $paused = 0;
        if (! $allowed['allowed']) {
            // Closing a provider stops it at every store's checkout at once (usableAtCheckout checks it);
            // their accounts stay, switched off, so nothing starts again by itself when it reopens.
            $paused = PaymentProviderAccount::query()->withoutTenantScope()->where('provider', $provider)->where('environment', 'production')->where('is_enabled', true)->update(['is_enabled' => false]);
        }
        $audit->record($allowed['allowed'] ? 'payments.provider_opened_for_live' : 'payments.provider_closed_for_live', ['provider' => $provider, 'reason' => $allowed['reason'], 'store_accounts_switched_off' => $paused], null, null, $request->user(), platform: true);

        return response()->json(['data' => ['live_providers' => $next, 'store_accounts_switched_off' => $paused]]);
    }

    public function saveAccount(Request $request, string $provider): JsonResponse
    {
        $data = $request->validate(['environment' => ['required', 'string'], 'credentials' => ['nullable', 'array'], 'credentials.*' => ['nullable', 'string', 'max:200']]);
        $account = $this->accounts->save(PlatformPaymentAccount::query()->where('provider', $provider)->first(), $provider, $data, $request->user(), null);

        return response()->json(['data' => $this->accounts->describe($this->providers->get($provider), $account)]);
    }

    public function setAccountEnabled(Request $request, string $provider): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $account = PlatformPaymentAccount::query()->where('provider', $provider)->firstOrFail();
        try {
            $account = $this->accounts->setEnabled($account, $data['enabled'], $request->user(), null);
        } catch (OnlinePaymentRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], $e->status);
        }

        return response()->json(['data' => $this->accounts->describe($this->providers->get($provider), $account)]);
    }

    public function disconnectAccount(Request $request, string $provider): JsonResponse
    {
        $account = PlatformPaymentAccount::query()->where('provider', $provider)->firstOrFail();
        try {
            $this->accounts->disconnect($account, $request->user(), null);
        } catch (OnlinePaymentRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], $e->status);
        }

        return response()->json(null, 204);
    }

    /** Module 12 §93: transaction lookup across stores, by our reference or the provider's. */
    public function attempts(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([PaymentAttempt::PENDING, PaymentAttempt::SUCCEEDED, PaymentAttempt::FAILED, PaymentAttempt::EXPIRED, PaymentAttempt::UNKNOWN])],
            'provider' => ['nullable', 'string', 'max:32'],
        ]);
        $rows = PaymentAttempt::query()->withoutTenantScope()
            ->when($filters['q'] ?? null, fn ($q, string $term) => $q->where(fn ($w) => $w->where('reference', $term)->orWhere('provider_transaction_reference', $term)))
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->when($filters['provider'] ?? null, fn ($q, string $provider) => $q->where('provider', $provider))
            ->latest('id')->paginate(25);
        $stores = Store::query()->withTrashed()->whereIn('id', collect($rows->items())->pluck('store_id'))->pluck('name', 'id');

        return response()->json([
            'data' => collect($rows->items())->map(fn (PaymentAttempt $a) => [
                ...(new StaffPaymentAttemptResource($a))->toArray($request),
                'store' => ['id' => $a->store_id, 'name' => $stores[$a->store_id] ?? null],
            ])->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total(), 'per_page' => $rows->perPage()],
        ]);
    }

    public function findings(Request $request): JsonResponse
    {
        $rows = PaymentReconciliationItem::query()->withoutTenantScope()->with('attempt')->where('status', 'open')->latest('id')->paginate(25);
        $stores = Store::query()->withTrashed()->whereIn('id', collect($rows->items())->pluck('store_id'))->pluck('name', 'id');

        return response()->json([
            'data' => collect($rows->items())->map(fn (PaymentReconciliationItem $item) => [
                'id' => $item->public_id, 'kind' => $item->kind, 'created_at' => $item->created_at->toIso8601String(),
                'store' => ['id' => $item->store_id, 'name' => $stores[$item->store_id] ?? null],
                'attempt' => $item->attempt === null ? null : (new StaffPaymentAttemptResource($item->attempt))->toArray($request),
            ])->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total(), 'per_page' => $rows->perPage()],
        ]);
    }
}
