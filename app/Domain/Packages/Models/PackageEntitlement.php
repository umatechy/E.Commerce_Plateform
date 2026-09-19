<?php

declare(strict_types=1);

namespace App\Domain\Packages\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The declaration of what a Package includes: a feature flag (on/off) or
 * a usage limit (numeric ceiling). Module 04 §28 explicitly requires
 * Feature Flags and Commercial Entitlements to remain distinct concepts
 * — `type` distinguishes them so application code never conflates
 * "is this feature code shipped" (a feature flag, a deploy-time concern)
 * with "is this store commercially allowed to use it" (an entitlement,
 * a Package-tier concern enforced at runtime).
 */
final class PackageEntitlement extends Model
{
    protected $table = 'package_entitlements';

    protected $fillable = [
        'package_id',
        'key',
        'type',
        'enforcement',
        'period',
        'boolean_value',
        'limit_value',
        'is_unlimited',
    ];

    protected function casts(): array
    {
        return [
            'type' => EntitlementType::class,
            'enforcement' => EntitlementEnforcement::class,
            'period' => UsagePeriod::class,
            'boolean_value' => 'boolean',
            'limit_value' => 'integer',
            'is_unlimited' => 'boolean',
        ];
    }
}
