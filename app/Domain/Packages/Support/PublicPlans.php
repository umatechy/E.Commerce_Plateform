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
        // Selling
        'payment.cod', 'payment.online', 'payment.bank_transfer', 'products.variants', 'promotions.basic', 'wishlist.basic',
        'pwa.enabled', 'domains.custom_domain', 'seo.basic', 'marketing.basic',
        // Growing
        'reviews.product', 'products.units_sold', 'customers.advanced', 'affiliate.programs', 'reports.advanced', 'advanced_analytics.enabled',
        'inventory.multi_warehouse', 'custom_roles.enabled', 'integrations.api', 'loyalty.points',
        // Design
        'themes.business', 'homepage.advanced_sections', 'animation.advanced', 'theme.custom_css',
        'themes.premium', 'layout.advanced', 'homepage.reorder', 'animation.premium',
    ];

    /** The usage limits the home page names. A package that leaves one unset is not limited by the platform (EntitlementService::limitFor). */
    private const LIMITS = ['max_products', 'max_staff_accounts', 'max_monthly_orders', 'max_storage_gb'];

    /** @return list<array{code: string, name: string, price: ?array{amount_minor: int, first_period_amount_minor: ?int, currency: string, interval: string}, limits: array<string, ?int>, features: list<string>, services: list<string>}> */
    public static function all(): array
    {
        $prices = PackagePrice::query()->where('is_active', true)->get()->groupBy('package_id');

        return Package::query()->where('is_active', true)->with('entitlements')->orderBy('id')->get()
            ->map(function (Package $package) use ($prices) {
                $mine = $prices[$package->id] ?? collect();
                // The interval new stores are billed in first, in the platform's billing currency where there is one.
                $interval = (string) app(\App\Domain\Settings\Services\ConfigService::class)->get('billing.default_interval');
                $currency = (string) config('billing.currency');
                $price = $mine->sortBy(fn (PackagePrice $p) => [$p->billing_interval->value === $interval ? 0 : 1, $p->currency === $currency ? 0 : 1])->first();
                $features = [];
                $limits = array_fill_keys(self::LIMITS, null);
                foreach ($package->entitlements as $e) {
                    if ($e->type === EntitlementType::Feature && $e->boolean_value && in_array($e->key, self::SHOWN_FEATURES, true)) {
                        $features[] = $e->key;
                    }
                    if ($e->type === EntitlementType::UsageLimit && in_array($e->key, self::LIMITS, true)) {
                        $limits[$e->key] = $e->is_unlimited ? null : (int) $e->limit_value;
                    }
                }
                usort($features, fn (string $a, string $b) => array_search($a, self::SHOWN_FEATURES, true) <=> array_search($b, self::SHOWN_FEATURES, true));

                return [
                    'code' => $package->code,
                    'name' => $package->name,
                    'price' => $price === null ? null : ['amount_minor' => $price->amount_minor, 'first_period_amount_minor' => $price->first_period_amount_minor, 'currency' => $price->currency, 'interval' => $price->billing_interval->value],
                    'limits' => $limits,
                    'features' => $features,
                    'services' => array_values($package->services ?? []),
                ];
            })->values()->all();
    }
}
