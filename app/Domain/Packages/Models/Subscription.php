<?php

declare(strict_types=1);

namespace App\Domain\Packages\Models;

use App\Domain\Tenancy\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Store's link to its current Package tier (Module 04, Module 29
 * Billing). Tenant-scoped: each Store has its own Subscription
 * lifecycle, independent of every other store's.
 *
 * Upgrade/downgrade changes ONLY package_id / status / limits on this
 * row — it NEVER creates a new Store (Master Index rule #2, this
 * prompt's "Store / Tenant Data Model" section).
 */
final class Subscription extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'subscriptions';

    protected $fillable = [
        'store_id',
        'package_id',
        'status',
        'trial_ends_at',
        'grace_period_ends_at',
        'current_period_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'trial_ends_at' => 'datetime',
            'grace_period_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
