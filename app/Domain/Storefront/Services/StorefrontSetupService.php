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

    /**
     * Phase B44 — Module 03 §24–25, §57–58: the setup checklist, in three
     * groups (essentials, running the store, growing it). Required steps
     * block the launch; optional ones are shown with the rest. Steps the
     * package does not include are not shown (§58): a custom domain only
     * with domains.custom_domain. When Umar Techy requires payment before
     * launch (platform.launch_requires_payment), "first payment" is
     * required: a paid invoice, or a subscription already past its trial.
     *
     * @return list<array{key: string, group: string, required: bool, done: bool, message: string}>
     */
    public function checklist(Store $store): array
    {
        $sellable = Product::query()
            ->where('status', ProductStatus::Active->value)
            ->where('visibility', ProductVisibility::Public->value)
            ->where(fn ($q) => $q->whereNotNull('price_minor')
                ->orWhereHas('variants', fn ($v) => $v->where('status', 'active')->whereNotNull('price_minor')))
            ->exists();

        $config = app(\App\Domain\Settings\Services\ConfigService::class);
        $entitlements = app(\App\Domain\Packages\Services\EntitlementService::class);

        $checks = [
            // Essentials.
            $this->check('business_info', true, ! empty($config->get('store.contact_email')), 'A contact email for your store, in Settings (business information).', 'essentials'),
            $this->check('products', true, $sellable, 'At least one active, public product with a price.', 'essentials'),
            $this->check('subscription', true, $this->gate->subscriptionGrantsAccess($store), 'A subscription that is active or on trial.', 'essentials'),
        ];
        if ($this->launchRequiresPayment()) {
            $checks[] = $this->check('payment', true, $this->hasPaid($store), 'Your first payment to Umar Techy. Get your first invoice below, pay it, and the team confirms it.', 'essentials');
        }

        return [
            ...$checks,
            $this->check('categories', false, \App\Domain\Catalog\Models\Category::query()->where('status', 'active')->exists(), 'Categories, so shoppers can browse.', 'essentials'),
            $this->check('branding', false, ! empty($this->themes->resolvePublished($store)['config']['branding']['logo_url']), 'A logo in the published theme.', 'essentials'),
            // Running the store.
            $this->check('payment_methods', false, app(StorefrontExperience::class)->paymentMethods() !== [], 'A way for shoppers to pay (cash on delivery or bank transfer).', 'operations'),
            $this->check('shipping', false, ShippingMethod::query()->where('is_active', true)->exists(), 'An active shipping method, so physical orders can be delivered.', 'operations'),
            $this->check('warehouse', false, Warehouse::query()->where('is_default', true)->exists(), 'A default warehouse, so stock is shown and reserved.', 'operations'),
            $this->check('policies', false, \App\Domain\Seo\Models\ContentPage::query()->where('status', 'published')->exists(), 'Your store policies (returns, shipping, privacy) as published pages.', 'operations'),
            $this->check('test_order', false, \App\Domain\Orders\Models\Order::query()->exists(), 'A test order, to see the whole journey once.', 'operations'),
            // Growing it.
            $this->check('seo', false, ! empty(\App\Domain\Seo\Models\SeoSetting::query()->where('seoable_type', 'store')->whereNull('seoable_id')->value('meta_description')), 'A search engine description for your home page.', 'growth'),
            ...($entitlements->hasFeature('domains.custom_domain') ? [
                $this->check('domain', false, Domain::query()->withoutTenantScope()->where('store_id', $store->id)
                    ->where('domain_type', DomainType::CustomDomain->value)->where('status', DomainStatus::Active->value)->exists(),
                    'A custom domain (the store is always reachable on its platform subdomain).', 'growth'),
            ] : []),
        ];
    }

    /** Phase B44: Umar Techy's rule that a store goes live only after its first payment. */
    public function launchRequiresPayment(): bool
    {
        return (bool) app(\App\Domain\Settings\Services\ConfigService::class)->get('platform.launch_requires_payment');
    }

    /** A paid invoice, or a subscription that is already past its trial (paid before). */
    public function hasPaid(Store $store): bool
    {
        $subscription = \App\Domain\Packages\Models\Subscription::query()->withoutTenantScope()->where('store_id', $store->id)->latest('id')->first();

        return $subscription?->status === \App\Domain\Packages\Models\SubscriptionStatus::Active
            || \App\Domain\Billing\Models\Invoice::query()->withoutTenantScope()->where('store_id', $store->id)->where('status', \App\Domain\Billing\Models\InvoiceStatus::Paid->value)->exists();
    }

    /**
     * @return array{launched: bool, availability: string, checks: list<array{key: string, group: string, required: bool, done: bool, message: string}>, progress: array{done: int, total: int, percent: int}, blocking: list<string>, requires_payment: bool}
     */
    public function status(Store $store): array
    {
        $checks = $this->checklist($store);
        $done = count(array_filter($checks, fn (array $c) => $c['done']));

        return [
            'launched' => $store->status !== StoreStatus::PendingSetup,
            'availability' => $this->gate->availability($store)->value,
            'checks' => $checks,
            // Phase B44 (Module 03 §24 "completion percentage", §57).
            'progress' => ['done' => $done, 'total' => count($checks), 'percent' => count($checks) === 0 ? 100 : (int) floor($done * 100 / count($checks))],
            'blocking' => array_values(array_map(fn (array $c) => $c['key'], array_filter($checks, fn (array $c) => $c['required'] && ! $c['done']))),
            'requires_payment' => $this->launchRequiresPayment(),
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

            $store->update(['status' => StoreStatus::Active, 'activated_at' => now()]);

            app(AuditLogger::class)->record('storefront.launched', [
                'optional_steps_pending' => collect($checks)->reject(fn (array $c) => $c['done'])->pluck('key')->values()->all(),
            ], $store, $store->id);
            $this->outbox->recordEventFor($store->id, 'store.launched', ['store_id' => $store->id], "store:{$store->id}:launched");

            return ['ok' => true];
        });
    }

    /** @return array{key: string, group: string, required: bool, done: bool, message: string} */
    private function check(string $key, bool $required, bool $done, string $message, string $group): array
    {
        return ['key' => $key, 'group' => $group, 'required' => $required, 'done' => $done, 'message' => $message];
    }
}
