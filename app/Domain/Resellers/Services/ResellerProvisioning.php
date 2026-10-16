<?php

declare(strict_types=1);

namespace App\Domain\Resellers\Services;

use App\Domain\Billing\Models\PackagePrice;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\Package;
use App\Domain\Resellers\Models\Reseller;
use App\Domain\Resellers\Models\ResellerStore;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Services\StoreProvisioningService;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Phase B58 (Module 28 §28, §96, §120) — a reseller creates a store for a
 * client from the partner portal:
 *
 * reseller active and allowed by its tier → package active and priced →
 * store created by Module 03 (StoreProvisioningService) with the client
 * invited as owner, on the package's trial → attribution "provisioned" with
 * the tier's terms → the client accepts, sets up and pays the partner price.
 *
 * Idempotent by the reseller's key: a double click or a retry returns the
 * store made the first time (unique reseller + key in reseller_stores).
 */
final class ResellerProvisioning
{
    public function __construct(
        private readonly StoreProvisioningService $stores,
        private readonly ResellerAttribution $attribution,
    ) {}

    /**
     * What the reseller can sell: active packages with their prices, public
     * and partner (the tier's discount), worked out here (§26).
     *
     * @return list<array{code: string, name: string, prices: list<array{interval: string, currency: string, public_minor: int, partner_minor: int}>}>
     */
    public function packages(Reseller $reseller): array
    {
        $discount = (int) ($reseller->tier->client_discount_bp ?? 0);
        $prices = PackagePrice::query()->where('is_active', true)->orderBy('amount_minor')->get()->groupBy('package_id');

        return Package::query()->where('is_active', true)->orderBy('id')->get()
            ->filter(fn (Package $p) => $prices->has($p->id))
            ->map(fn (Package $p) => [
                'code' => $p->code, 'name' => $p->name,
                'prices' => $prices[$p->id]->map(fn (PackagePrice $price) => [
                    'interval' => $price->billing_interval->value, 'currency' => $price->currency,
                    'public_minor' => $price->amount_minor, 'partner_minor' => ResellerPricing::discounted($price->amount_minor, $discount),
                ])->values()->all(),
            ])->values()->all();
    }

    /** How many more stores the tier lets this reseller create (null: no limit). */
    public function remaining(Reseller $reseller): ?int
    {
        $max = $reseller->tier?->max_stores;

        return $max === null ? null : max(0, $max - ResellerStore::query()->where('reseller_id', $reseller->id)->where('source', 'provisioned')->count());
    }

    /**
     * @param array{store_name: string, business_category: string, package_code: string, owner_email: string, starter_template?: bool} $data
     * @return array{store: Store, attribution: ResellerStore, created: bool}
     */
    public function provision(Reseller $reseller, User $by, array $data, string $key): array
    {
        if (($existing = $this->existing($reseller, $key)) !== null) {
            return ['store' => $existing->store, 'attribution' => $existing, 'created' => false];
        }
        $this->refuseIf(! $reseller->isActive(), 'reseller_not_active', 'Your partner account is not active.');
        $this->refuseIf(! ($reseller->tier->can_provision ?? false), 'provisioning_not_allowed', 'Your partner tier does not include creating stores. Send your clients your sign-up link instead.');
        $this->refuseIf($this->remaining($reseller) === 0, 'store_limit', 'You have created as many stores as your tier allows.');
        $package = Package::query()->where('code', $data['package_code'])->where('is_active', true)->first();
        $this->refuseIf($package === null || ! PackagePrice::query()->where('package_id', $package->id)->where('is_active', true)->exists(), 'package_unavailable', 'Choose a package that is on sale.');

        $attribution = null;
        try {
            $store = $this->stores->forReseller($by, [
                'store_name' => $data['store_name'], 'business_category' => $data['business_category'], 'package' => $package,
                'owner_email' => $data['owner_email'], 'starter_template' => $data['starter_template'] ?? true,
            ], function (Store $store) use ($reseller, $by, $key, &$attribution) {
                $attribution = $this->attribution->attach($reseller, $store, 'provisioned', $by, null, $key);
            });
        } catch (UniqueConstraintViolationException) {
            // The same request ran twice at once: the other one made the store.
            $existing = $this->existing($reseller, $key) ?? throw new ResellerRefused('try_again', 'Please try again.');

            return ['store' => $existing->store, 'attribution' => $existing, 'created' => false];
        }

        /** @var ResellerStore $attribution */
        return ['store' => $store, 'attribution' => $attribution, 'created' => true];
    }

    private function existing(Reseller $reseller, string $key): ?ResellerStore
    {
        return ResellerStore::query()->with('store')->where('reseller_id', $reseller->id)->where('provision_key', $key)->first();
    }

    private function refuseIf(bool $condition, string $code, string $message): void
    {
        if ($condition) {
            throw new ResellerRefused($code, $message);
        }
    }
}
