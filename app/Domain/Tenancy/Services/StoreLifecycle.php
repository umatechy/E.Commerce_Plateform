<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase B44 — Module 03 §7–8: where a store is in its life, for people
 * (Super Admin list, the owner's dashboard).
 *
 * Module 03 suggests store states PROVISIONING, ONBOARDING, TRIAL, ACTIVE,
 * GRACE_PERIOD, SUSPENDED, CANCELLED, ARCHIVED, DELETING. Three of them are
 * already subscription states (Module 04 §17: trial, past due, grace
 * period) kept by the billing engine; storing them on the store too would
 * give two sources of truth that drift apart. So the store keeps its own
 * operational status (pending_setup, active, suspended, cancelled,
 * archived) and the stage shown is derived from both, plus whether the
 * store has an owner yet. PROVISIONING needs no state: provisioning is one
 * transaction, so a half-made store never exists (§56). DELETING belongs to
 * the closure workflow (gap G14).
 */
final class StoreLifecycle
{
    public const STAGES = ['awaiting_owner', 'onboarding', 'trial', 'active', 'payment_due', 'grace_period', 'suspended', 'cancelled', 'archived'];

    public static function stage(Store $store, ?Subscription $subscription, bool $hasOwner): string
    {
        return match (true) {
            $store->status === StoreStatus::Archived || $subscription?->status === SubscriptionStatus::Archived => 'archived',
            $store->status === StoreStatus::Cancelled || $subscription?->status === SubscriptionStatus::Cancelled => 'cancelled',
            $store->status === StoreStatus::Suspended,
            in_array($subscription?->status, [SubscriptionStatus::Suspended, SubscriptionStatus::Expired], true) => 'suspended',
            ! $hasOwner => 'awaiting_owner',
            $store->status === StoreStatus::PendingSetup || $subscription === null || $subscription->status === SubscriptionStatus::Pending => 'onboarding',
            $subscription->status === SubscriptionStatus::Trialing => 'trial',
            $subscription->status === SubscriptionStatus::PastDue => 'payment_due',
            $subscription->status === SubscriptionStatus::GracePeriod => 'grace_period',
            default => 'active',
        };
    }

    /**
     * The stage of many stores with three queries (Super Admin list).
     *
     * @param Collection<int, Store> $stores
     * @return array<int, array{stage: string, subscription: ?Subscription, has_owner: bool, owner_email: ?string}>
     */
    public static function forStores(Collection $stores): array
    {
        $ids = $stores->pluck('id')->all();
        if ($ids === []) {
            return [];
        }
        $subscriptions = Subscription::query()->withoutTenantScope()->with('package')->whereIn('store_id', $ids)
            ->orderBy('id')->get()->keyBy('store_id'); // the latest row per store wins
        $owners = DB::table('store_user')
            ->join('roles', 'roles.id', '=', 'store_user.role_id')
            ->join('users', 'users.id', '=', 'store_user.user_id')
            ->whereIn('store_user.store_id', $ids)->where('roles.slug', 'owner')->where('store_user.status', 'active')
            ->pluck('users.email', 'store_user.store_id');
        $invited = DB::table('store_invitations')
            ->join('roles', 'roles.id', '=', 'store_invitations.role_id')
            ->whereIn('store_invitations.store_id', $ids)->where('roles.slug', 'owner')->where('store_invitations.status', 'pending')
            ->where('store_invitations.expires_at', '>', now())
            ->pluck('store_invitations.email', 'store_invitations.store_id');

        $out = [];
        foreach ($stores as $store) {
            $subscription = $subscriptions->get($store->id);
            $hasOwner = $owners->has($store->id);
            $out[$store->id] = [
                'stage' => self::stage($store, $subscription, $hasOwner),
                'subscription' => $subscription,
                'has_owner' => $hasOwner,
                'owner_email' => $hasOwner ? (string) $owners[$store->id] : ($invited->has($store->id) ? (string) $invited[$store->id] : null),
            ];
        }

        return $out;
    }
}
