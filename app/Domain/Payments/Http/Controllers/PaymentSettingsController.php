<?php

declare(strict_types=1);

namespace App\Domain\Payments\Http\Controllers;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Payments\Exceptions\OnlinePaymentRefusedException;
use App\Domain\Payments\Http\Resources\StaffPaymentAttemptResource;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Models\PaymentProviderAccount;
use App\Domain\Payments\Providers\ProviderRegistry;
use App\Domain\Payments\Services\OnlinePaymentService;
use App\Domain\Payments\Services\PaymentOptions;
use App\Domain\Payments\Services\ProviderAccountService;
use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Phase B48 (gap G9; Module 12 §16, §18–19, §31–37, §94): the store's
 * Payments settings — cash on delivery, bank transfer and the store's own
 * merchant accounts with JazzCash and Easypaisa.
 *
 * Reading needs payments.view; every change needs payments.provider_config
 * (Owner by default) and, for bank details and accounts, a fresh password
 * confirmation (step-up, on the routes): changing where money goes is the
 * classic insider fraud.
 */
final class PaymentSettingsController
{
    public function __construct(
        private readonly ConfigService $config,
        private readonly ProviderRegistry $providers,
        private readonly ProviderAccountService $accounts,
        private readonly OnlinePaymentService $online,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function show(Request $request, PaymentOptions $options, EntitlementService $entitlements): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', Payment::class);
        $accounts = PaymentProviderAccount::query()->get()->keyBy('provider');

        return response()->json(['data' => [
            'can_configure' => Gate::forUser($request->user())->allows('configure', Payment::class),
            'online_included' => $entitlements->hasFeature('payment.online'),
            'currency' => (string) $this->config->get('store.default_currency'),
            'cod' => [
                'enabled' => (bool) $this->config->get('payments.cod.enabled'),
                'min_order_minor' => (int) $this->config->get('payments.cod.min_order_minor'),
                'max_order_minor' => (int) $this->config->get('payments.cod.max_order_minor'),
            ],
            'bank_transfer' => [
                'enabled' => (bool) $this->config->get('payments.bank_transfer.enabled'),
                'account_title' => $this->config->get('payments.bank.account_title'),
                'bank_name' => $this->config->get('payments.bank.bank_name'),
                'account_number' => $this->config->get('payments.bank.account_number'),
                'instructions' => $this->config->get('payments.bank.instructions'),
                'complete' => $options->bankDetails() !== null,
            ],
            'providers' => array_values(array_map(fn ($adapter) => $this->accounts->describe($adapter, $accounts->get($adapter->key())), $this->providers->all())),
            // Owner decision 2: card payments come through a card provider Umar Techy has not chosen yet.
            'card' => ['available' => false],
            'checkout_preview' => $options->methodCodes((string) $this->config->get('store.default_currency')),
        ]]);
    }

    public function updateCod(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('configure', Payment::class);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'min_order_minor' => ['required', 'integer', 'min:0'],
            'max_order_minor' => ['required', 'integer', 'min:0'],
        ]);
        if ($data['max_order_minor'] > 0 && $data['min_order_minor'] > $data['max_order_minor']) {
            return response()->json(['message' => 'The maximum must be at least the minimum.', 'errors' => ['max_order_minor' => ['The maximum must be at least the minimum.']]], 422);
        }

        $actor = $request->user()->id;
        DB::transaction(function () use ($data, $actor) {
            $this->config->set('payments.cod.enabled', $data['enabled'], SettingScope::Store, $actor);
            $this->config->set('payments.cod.min_order_minor', $data['min_order_minor'], SettingScope::Store, $actor);
            $this->config->set('payments.cod.max_order_minor', $data['max_order_minor'], SettingScope::Store, $actor);
        });

        return response()->json(['data' => ['saved' => true]]);
    }

    public function updateBankTransfer(Request $request, PaymentOptions $options): JsonResponse
    {
        Gate::forUser($request->user())->authorize('configure', Payment::class);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'account_title' => ['nullable', 'string', 'max:120'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'account_number' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9 \-]+$/'],
            'instructions' => ['nullable', 'string', 'max:500'],
        ]);
        $filled = trim((string) ($data['account_title'] ?? '')) !== '' && trim((string) ($data['bank_name'] ?? '')) !== '' && trim((string) ($data['account_number'] ?? '')) !== '';
        if ($data['enabled'] && ! $filled) {
            return response()->json(['message' => 'Fill in the account title, bank and account number before offering bank transfer.', 'code' => 'bank_details_incomplete'], 422);
        }

        DB::transaction(function () use ($data, $request) {
            $actor = $request->user()->id;
            $this->config->set('payments.bank_transfer.enabled', $data['enabled'], SettingScope::Store, $actor);
            foreach (['account_title', 'bank_name', 'account_number', 'instructions'] as $field) {
                $value = trim((string) ($data[$field] ?? ''));
                $value === ''
                    ? $this->config->clear("payments.bank.{$field}", SettingScope::Store, $actor)
                    : $this->config->set("payments.bank.{$field}", $value, SettingScope::Store, $actor);
            }
            // The settings log has the values; this entry marks the change of account as a payment event.
            $this->audit->record('payments.bank_details_changed', ['enabled' => $data['enabled']], null, $this->context->storeId(), $request->user());
        });

        return response()->json(['data' => ['saved' => true, 'complete' => $options->bankDetails() !== null]]);
    }

    public function saveProvider(Request $request, string $provider): JsonResponse
    {
        Gate::forUser($request->user())->authorize('configure', Payment::class);
        $data = $request->validate([
            'environment' => ['required', 'string'],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:200'],
            'min_amount_minor' => ['nullable', 'integer'],
            'max_amount_minor' => ['nullable', 'integer'],
        ]);
        $account = PaymentProviderAccount::query()->where('provider', $provider)->first();
        $account = $this->accounts->save($account, $provider, $data, $request->user(), $this->context->storeId());

        return response()->json(['data' => $this->accounts->describe($this->providers->get($provider), $account)], 200);
    }

    public function setEnabled(Request $request, string $provider): JsonResponse
    {
        Gate::forUser($request->user())->authorize('configure', Payment::class);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $account = PaymentProviderAccount::query()->where('provider', $provider)->firstOrFail();

        try {
            $account = $this->accounts->setEnabled($account, $data['enabled'], $request->user(), $this->context->storeId());
        } catch (OnlinePaymentRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], $e->status);
        }

        return response()->json(['data' => $this->accounts->describe($this->providers->get($provider), $account)]);
    }

    public function disconnect(Request $request, string $provider): JsonResponse
    {
        Gate::forUser($request->user())->authorize('configure', Payment::class);
        $account = PaymentProviderAccount::query()->where('provider', $provider)->firstOrFail();

        try {
            $this->accounts->disconnect($account, $request->user(), $this->context->storeId());
        } catch (OnlinePaymentRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], $e->status);
        }

        return response()->json(null, 204);
    }

    /** A Rs 1.00 payment against the sandbox account, to see that it works before going live. */
    public function test(Request $request, string $provider): JsonResponse
    {
        Gate::forUser($request->user())->authorize('configure', Payment::class);
        $data = $request->validate(['payer' => ['required', 'array'], 'payer.mobile' => ['required', 'string', 'max:20'], 'payer.cnic_last6' => ['nullable', 'string', 'max:10']]);
        $account = PaymentProviderAccount::query()->where('provider', $provider)->firstOrFail();

        try {
            $attempt = $this->online->startTest($account, $data['payer'], $request->user());
        } catch (OnlinePaymentRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], $e->status);
        }

        return response()->json(['data' => (new StaffPaymentAttemptResource($attempt))->toArray($request)], 201);
    }

    /** Follow one attempt (a test payment here; order attempts also show in the payment drawer). */
    public function attempt(Request $request, string $attempt): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', Payment::class);
        $model = PaymentAttempt::query()->where('public_id', $attempt)->firstOrFail();
        if ($model->isInFlight() && ($model->last_checked_at === null || $model->last_checked_at->lte(now()->subSeconds(10)))) {
            $model = $this->online->refresh($model);
        }

        return response()->json(['data' => (new StaffPaymentAttemptResource($model))->toArray($request)]);
    }
}
