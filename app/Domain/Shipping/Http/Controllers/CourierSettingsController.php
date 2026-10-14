<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Http\Controllers;

use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Shipping\Couriers\CourierRegistry;
use App\Domain\Shipping\Exceptions\FulfillmentNotAllowedException;
use App\Domain\Shipping\Models\CourierAccount;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\Policies\ShippingConfigPolicy;
use App\Domain\Shipping\Services\CourierAccountService;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Phase B49 (Module 13 §44–46, §57, §74): the store's couriers — the
 * accounts it connected, the parcel weight used when none is typed, and
 * the tracking link for "another courier". Reading needs shipments.view;
 * changing needs shipping_config.manage, and courier credentials also a
 * fresh password (step-up, on the routes).
 */
final class CourierSettingsController
{
    public function __construct(
        private readonly CourierRegistry $couriers,
        private readonly CourierAccountService $accounts,
        private readonly ConfigService $config,
        private readonly TenantContext $context,
    ) {}

    public function show(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', Shipment::class);
        $accounts = CourierAccount::query()->get()->keyBy('provider');

        return response()->json(['data' => [
            'can_configure' => app(ShippingConfigPolicy::class)->manage($request->user()),
            'default_weight_grams' => (int) $this->config->get('shipping.default_weight_grams'),
            'tracking_url_template' => $this->config->get('shipping.tracking_url_template'),
            'couriers' => array_values(array_map(fn ($adapter) => $this->accounts->describe($adapter, $accounts->get($adapter->key())), $this->couriers->all())),
            // Owner decision 2: no real courier is offered until one has been checked in its sandbox.
            'real_couriers' => CourierRegistry::REAL_COURIERS,
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless(app(ShippingConfigPolicy::class)->manage($request->user()), 403);
        $data = $request->validate([
            'default_weight_grams' => ['required', 'integer', 'min:1', 'max:100000'],
            'tracking_url_template' => ['nullable', 'string', 'max:255'],
        ]);
        $actor = $request->user()->id;
        try {
            DB::transaction(function () use ($data, $actor) {
                $this->config->set('shipping.default_weight_grams', $data['default_weight_grams'], SettingScope::Store, $actor);
                $template = trim((string) ($data['tracking_url_template'] ?? ''));
                $template === ''
                    ? $this->config->clear('shipping.tracking_url_template', SettingScope::Store, $actor)
                    : $this->config->set('shipping.tracking_url_template', $template, SettingScope::Store, $actor);
            });
        } catch (\App\Domain\Settings\Exceptions\InvalidSettingValueException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['tracking_url_template' => [$e->getMessage()]]], 422);
        }

        return response()->json(['data' => ['saved' => true]]);
    }

    public function saveCourier(Request $request, string $provider): JsonResponse
    {
        abort_unless(app(ShippingConfigPolicy::class)->manage($request->user()), 403);
        $data = $request->validate(['environment' => ['required', 'string'], 'credentials' => ['nullable', 'array'], 'credentials.*' => ['nullable', 'string', 'max:200']]);
        $account = $this->accounts->save(CourierAccount::query()->where('provider', $provider)->first(), $provider, $data, $request->user(), $this->context->storeId());

        return response()->json(['data' => $this->accounts->describe($this->couriers->get($provider), $account)]);
    }

    public function setEnabled(Request $request, string $provider): JsonResponse
    {
        abort_unless(app(ShippingConfigPolicy::class)->manage($request->user()), 403);
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $account = CourierAccount::query()->where('provider', $provider)->firstOrFail();

        return response()->json(['data' => $this->accounts->describe($this->couriers->get($provider), $this->accounts->setEnabled($account, $enabled, $request->user()))]);
    }

    public function disconnect(Request $request, string $provider): JsonResponse
    {
        abort_unless(app(ShippingConfigPolicy::class)->manage($request->user()), 403);
        $account = CourierAccount::query()->where('provider', $provider)->firstOrFail();
        try {
            $this->accounts->disconnect($account, $request->user());
        } catch (FulfillmentNotAllowedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'parcels_on_the_way'], 409);
        }

        return response()->json(null, 204);
    }
}
