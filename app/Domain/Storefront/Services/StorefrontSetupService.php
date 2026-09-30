<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductStatus;
use App\Domain\Catalog\Models\ProductVisibility;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Domains\Models\Domain;
use App\Domain\Domains\Models\DomainStatus;
use App\Domain\Domains\Models\DomainType;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreStatus;
use App\Domain\Theme\Services\ThemeResolver;
use Illuminate\Support\Facades\DB;

/**
 * Module 05 — the launch checklist and the launch itself. Before B24 a
 * registered store stayed `pending_setup` forever: nothing ever moved it
 * to `active`. The owner now launches it once the required checks pass.
 */
final class StorefrontSetupService
{
    public function __construct(
        private readonly StorefrontGate $gate,
        private readonly ThemeResolver $themes,
        private readonly RecordsOutboxEvents $outbox,
    ) {}

    /** @return list<array{key: string, required: bool, done: bool, message: string}> */
    public function checklist(Store $store): array
    {
        $sellable = Product::query()
            ->where('status', ProductStatus::Active->value)
            ->where('visibility', ProductVisibility::Public->value)
            ->where(fn ($q) => $q->whereNotNull('price_minor')
                ->orWhereHas('variants', fn ($v) => $v->where('status', 'active')->whereNotNull('price_minor')))
            ->exists();

        return [
            $this->check('products', true, $sellable, 'At least one active, public product with a price.'),
            $this->check('subscription', true, $this->gate->subscriptionGrantsAccess($store), 'A subscription that is active or on trial.'),
            $this->check('warehouse', false, Warehouse::query()->where('is_default', true)->exists(), 'A default warehouse, so stock is shown and reserved.'),
            $this->check('shipping', false, ShippingMethod::query()->where('is_active', true)->exists(), 'An active shipping method, so physical orders can be delivered.'),
            $this->check('branding', false, ! empty($this->themes->resolvePublished($store)['config']['branding']['logo_url']), 'A logo in the published theme.'),
            $this->check('domain', false, Domain::query()->withoutTenantScope()->where('store_id', $store->id)
                ->where('domain_type', DomainType::CustomDomain->value)->where('status', DomainStatus::Active->value)->exists(),
                'A custom domain (the store is always reachable on its platform subdomain).'),
        ];
    }

    /**
     * @return array{launched: bool, availability: string, checks: list<array{key: string, required: bool, done: bool, message: string}>}
     */
    public function status(Store $store): array
    {
        return [
            'launched' => $store->status !== StoreStatus::PendingSetup,
            'availability' => $this->gate->availability($store)->value,
            'checks' => $this->checklist($store),
        ];
    }

    /**
     * @return array{ok: bool, code?: string, checks?: list<array{key: string, required: bool, done: bool, message: string}>}
     */
    public function launch(Store $store): array
    {
        return DB::transaction(function () use ($store) {
            $store = Store::query()->lockForUpdate()->findOrFail($store->id);

            if ($store->status !== StoreStatus::PendingSetup) {
                return ['ok' => false, 'code' => $store->status === StoreStatus::Active ? 'already_launched' : 'store_not_launchable'];
            }

            $checks = $this->checklist($store);
            if (collect($checks)->contains(fn (array $c) => $c['required'] && ! $c['done'])) {
                return ['ok' => false, 'code' => 'setup_incomplete', 'checks' => $checks];
            }

            $store->update(['status' => StoreStatus::Active]);

            app(AuditLogger::class)->record('storefront.launched', [
                'optional_steps_pending' => collect($checks)->reject(fn (array $c) => $c['done'])->pluck('key')->values()->all(),
            ], $store, $store->id);
            $this->outbox->recordEventFor($store->id, 'store.launched', ['store_id' => $store->id], "store:{$store->id}:launched");

            return ['ok' => true];
        });
    }

    /** @return array{key: string, required: bool, done: bool, message: string} */
    private function check(string $key, bool $required, bool $done, string $message): array
    {
        return ['key' => $key, 'required' => $required, 'done' => $done, 'message' => $message];
    }
}
