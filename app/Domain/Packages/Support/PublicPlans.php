<?php

declare(strict_types=1);

namespace App\Domain\Packages\Support;

use App\Domain\Billing\Models\PackagePrice;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;

/**
 * UI-1 — the packages as the public home page shows them: active packages
 * with their monthly price (as set by Umar Techy; nothing invented here),
 * their limits and the features they include.
 *
 * Only features the platform really has are listed: a package may name a
 * feature that is not built yet (for example AI), and the home page never
 * promises it.
 */
final class PublicPlans
{
    /** Built features worth naming on the home page, in the order they are shown. */
    private const SHOWN_FEATURES = [
        'payment.cod', 'payment.online', 'payment.bank_transfer', 'promotions.basic', 'reviews.product', 'pwa.enabled',
        'domains.custom_domain', 'customers.advanced', 'marketing.basic', 'affiliate.programs', 'reports.advanced',
        'inventory.multi_warehouse', 'custom_roles.enabled', 'themes.business', 'themes.premium', 'integrations.api',
    ];

    /** @return list<array{code: string, name: string, price: ?array{amount_minor: int, currency: string, interval: string}, limits: array<string, ?int>, features: list<string>}> */
    public static function all(): array
    {
        $prices = PackagePrice::query()->where('is_active', true)->get()->groupBy('package_id');

        return Package::query()->where('is_active', true)->with('entitlements')->orderBy('id')->get()
            ->map(function (Package $package) use ($prices) {
                $mine = $prices[$package->id] ?? collect();
                // Monthly first, in the platform's first currency where there is one.
                $price = $mine->sortBy(fn (PackagePrice $p) => [$p->billing_interval->value === 'monthly' ? 0 : 1, $p->currency === 'PKR' ? 0 : 1])->first();
                $features = [];
                $limits = [];
                foreach ($package->entitlements as $e) {
                    if ($e->type === EntitlementType::Feature && $e->boolean_value && in_array($e->key, self::SHOWN_FEATURES, true)) {
                        $features[] = $e->key;
                    }
                    if ($e->type === EntitlementType::UsageLimit) {
                        $limits[$e->key] = $e->is_unlimited ? null : (int) $e->limit_value;
                    }
                }
                usort($features, fn (string $a, string $b) => array_search($a, self::SHOWN_FEATURES, true) <=> array_search($b, self::SHOWN_FEATURES, true));

                return [
                    'code' => $package->code,
                    'name' => $package->name,
                    'price' => $price === null ? null : ['amount_minor' => $price->amount_minor, 'currency' => $price->currency, 'interval' => $price->billing_interval->value],
                    'limits' => $limits,
                    'features' => $features,
                ];
            })->values()->all();
    }
}
