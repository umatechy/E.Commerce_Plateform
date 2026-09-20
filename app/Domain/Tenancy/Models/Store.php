<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The Tenant. "Store" is the platform's tenant unit (Bible §6 Tenant
 * Model, Module 03). Store.id is immutable across package upgrade/
 * downgrade (Master Index non-negotiable rule #2) — a package change
 * NEVER creates a new Store row, only changes the linked Subscription.
 *
 * This is a platform-level model, not itself tenant-scoped by
 * BelongsToTenant — a Store does not belong to another Store.
 */
final class Store extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'stores';

    /**
     * Phase B7: payment_webhook_secret must never leak through a raw
     * $store->toArray()/toJson() call even though StoreResource
     * already excludes it via an explicit allow-list — this is a
     * defense-in-depth backstop, not the only protection.
     */
    protected $hidden = ['payment_webhook_secret', 'shipment_webhook_secret', 'notification_signing_secret'];

    protected $fillable = [
        'name',
        'slug',
        'status',
        'allow_overselling',
        'payment_webhook_secret',
        'shipment_webhook_secret',
        'notification_signing_secret',
    ];

    protected function casts(): array
    {
        return [
            'status' => StoreStatus::class,
            'allow_overselling' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'store_user')
            ->withPivot(['role_id', 'status'])
            ->withTimestamps();
    }

    /**
     * The store's current subscription row, regardless of status. B2
     * fix (see docs/development/b2-inspection-findings.md): the
     * original `activeSubscription()` relation below is filtered to
     * literal status = 'active', which would silently exclude
     * Trialing/GracePeriod/PastDue subscriptions — exactly the states
     * SubscriptionStatus::grantsAccess() says SHOULD still grant
     * feature access. EntitlementService and SubscriptionLifecycleService
     * both use THIS relation, not activeSubscription(), so a trialing
     * store's entitlements resolve correctly.
     */
    public function currentSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /**
     * Kept for any caller that explicitly wants only a literal
     * status = 'active' subscription (e.g. a billing report that
     * intentionally excludes trials). Prefer currentSubscription() for
     * anything related to feature/entitlement access.
     */
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->where('status', 'active');
    }
}
