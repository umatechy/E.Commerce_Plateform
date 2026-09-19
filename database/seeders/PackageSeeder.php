<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Packages\Models\EntitlementEnforcement;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\UsagePeriod;
use Illuminate\Database\Seeder;

/**
 * Platform package catalog (Module 04 §3 "Initial Package Structure",
 * §8 "Package Feature Matrix" — the module's own illustrative
 * conceptual matrix, used as-is, not invented). Idempotent
 * (updateOrCreate throughout) — safe to re-run, never destructive, per
 * this milestone's "Seeding" section.
 *
 * IMPORTANT — what is and is NOT seeded here, and why (see
 * docs/architecture/b2-packages-entitlements.md for the full
 * rationale):
 *  - Feature flags (booleans) for all three packages ARE seeded,
 *    directly from Module 04 §8's matrix.
 *  - Numeric usage limits are seeded ONLY for Basic, using the exact
 *    numbers Module 04 §9/§11/§22 uses as its own recurring worked
 *    examples (max_products=500, max_staff_accounts=5,
 *    max_monthly_orders=1000, max_storage_gb=10) — not invented values.
 *  - Business and Premium get NO numeric usage_limit rows at all.
 *    EntitlementService::limitFor() treats a missing usage_limit row as
 *    unlimited (see its docblock) — this is the deliberate, documented
 *    way to avoid inventing Business/Premium numbers while still
 *    giving them correct (better-than-Basic) behavior immediately.
 *    Umar Techy sets real numbers for these tiers via the Super Admin
 *    package management API before commercial launch.
 *  - "LIMITED" tiers in Module 04's matrix (e.g. Basic product
 *    variants, Business API access) are simplified to a plain boolean
 *    in this initial seed — flagged here, not silently done, because
 *    the entitlement model does not yet have a three-state
 *    "limited" concept. Revisit when Module 06 (Catalog) or Module 31
 *    (Developer API) actually need the finer-grained behavior.
 */
final class PackageSeeder extends Seeder
{
    /** @var array<string, array<string, bool>> package code => feature key => enabled */
    private const FEATURE_MATRIX = [
        'basic' => [
            'products.variants' => true, // "LIMITED" in Module 04 §8 — simplified to true, see class docblock
            'inventory.advanced' => false,
            'reports.advanced' => false,
            'advanced_analytics.enabled' => false,
            'loyalty.points' => false,
            'ai.recommendations' => false,
            'ai.chatbot' => false,
            'integrations.advanced' => false,
            'integrations.api' => false,
            'custom_roles.enabled' => false,
            'marketing.advanced' => false,
            'pwa.enabled' => true,
            'inventory.multi_warehouse' => false,
            'orders.basic' => true, // Module 09 — core ordering available to every tier
            'wishlist.basic' => true, // Module 11 §64-71 — core wishlist available to every tier (Phase B6)
            'payment.cod' => true, // Module 12 — every tier gets all 3 B7 methods; no tier restriction is specified by the module, so none is invented
            'payment.bank_transfer' => true,
            'payment.online' => true,
        ],
        'business' => [
            'products.variants' => true,
            'inventory.advanced' => true,
            'reports.advanced' => true,
            'advanced_analytics.enabled' => false,
            'loyalty.points' => false,
            'ai.recommendations' => false,
            'ai.chatbot' => false,
            'integrations.advanced' => true,
            'integrations.api' => true, // "LIMITED" in Module 04 §8 — simplified to true, see class docblock
            'custom_roles.enabled' => true,
            'marketing.advanced' => true,
            'pwa.enabled' => true,
            'inventory.multi_warehouse' => true, // Module 08 §71/§16 — Business+
            'orders.basic' => true,
            'wishlist.basic' => true,
            'payment.cod' => true, // Module 12 — every tier gets all 3 B7 methods; no tier restriction is specified by the module, so none is invented
            'payment.bank_transfer' => true,
            'payment.online' => true,
        ],
        'premium' => [
            'products.variants' => true,
            'inventory.advanced' => true,
            'reports.advanced' => true,
            'advanced_analytics.enabled' => true,
            'loyalty.points' => true,
            'ai.recommendations' => true,
            'ai.chatbot' => true,
            'integrations.advanced' => true,
            'integrations.api' => true,
            'custom_roles.enabled' => true,
            'marketing.advanced' => true,
            'pwa.enabled' => true,
            'inventory.multi_warehouse' => true, // Module 08 §71/§16 — Business+/Premium
            'orders.basic' => true,
            'wishlist.basic' => true,
            'payment.cod' => true, // Module 12 — every tier gets all 3 B7 methods; no tier restriction is specified by the module, so none is invented
            'payment.bank_transfer' => true,
            'payment.online' => true,
        ],
    ];

    /** Basic-only numeric limits — see class docblock for why. */
    private const BASIC_USAGE_LIMITS = [
        'max_products' => ['limit' => 500, 'period' => UsagePeriod::Persistent, 'enforcement' => EntitlementEnforcement::Hard],
        'max_staff_accounts' => ['limit' => 5, 'period' => UsagePeriod::Persistent, 'enforcement' => EntitlementEnforcement::Hard],
        'max_monthly_orders' => ['limit' => 1000, 'period' => UsagePeriod::Monthly, 'enforcement' => EntitlementEnforcement::Hard],
        'max_storage_gb' => ['limit' => 10, 'period' => UsagePeriod::Persistent, 'enforcement' => EntitlementEnforcement::Soft],
    ];

    public function run(): void
    {
        foreach (self::FEATURE_MATRIX as $code => $features) {
            $package = Package::query()->updateOrCreate(
                ['code' => $code],
                ['name' => ucfirst($code), 'is_active' => true]
            );

            foreach ($features as $key => $enabled) {
                $package->entitlements()->updateOrCreate(
                    ['key' => $key],
                    ['type' => EntitlementType::Feature, 'boolean_value' => $enabled]
                );
            }

            if ($code === 'basic') {
                foreach (self::BASIC_USAGE_LIMITS as $key => $config) {
                    $package->entitlements()->updateOrCreate(
                        ['key' => $key],
                        [
                            'type' => EntitlementType::UsageLimit,
                            'enforcement' => $config['enforcement'],
                            'period' => $config['period'],
                            'limit_value' => $config['limit'],
                            'is_unlimited' => false,
                        ]
                    );
                }
            }
        }
    }
}
