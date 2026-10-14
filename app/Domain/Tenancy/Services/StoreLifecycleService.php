<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Packages\Services\SubscriptionLifecycleService;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Exceptions\StoreLifecycleException;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreDeletion;
use App\Domain\Tenancy\Models\StoreLifecycleEvent;
use App\Domain\Tenancy\Models\StoreStatus;
use App\Support\RequestId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase B51 (gap G14; SRS STORE-003, STORE-009, MT-017; Module 03 §7–8,
 * §33–37; Module 30 §10, §140; owner decision 5) — the only place a store's
 * life changes.
 *
 * Every change: locks the store row, checks the move is allowed from the
 * current state (TRANSITIONS — "a store should never move directly between
 * arbitrary states", §7), writes the store, a lifecycle event, the audit
 * record and an outbox event in one transaction (ADR-004). Nothing here
 * removes data: closing, suspending and archiving only change what may be
 * done; removal happens only in an approved StoreDeletion run by
 * StorePurger, after the retention period, a billing and legal check, a
 * cooling period and a verified backup.
 *
 * The store's own status is the operational one; billing keeps the
 * subscription's (trial, past due, grace period) as before (StoreLifecycle).
 */
final class StoreLifecycleService
{
    /** action => states it may start from */
    public const TRANSITIONS = [
        'close' => ['pending_setup', 'active', 'suspended'],
        'reopen' => ['cancelled'],
        'suspend' => ['pending_setup', 'active', 'cancelled'],
        'reactivate' => ['suspended'],
        'archive' => ['cancelled', 'suspended'],
        'restore' => ['archived'],
        'request_deletion' => ['archived'],
        'cancel_deletion' => ['archived', 'deleting'],
        'start_deletion' => ['archived'],
        'finish_deletion' => ['deleting'],
    ];

    /** Why Umar Techy suspends a store (Module 03 §33). */
    public const SUSPENSION_REASONS = ['abuse', 'security_incident', 'policy', 'customer_request', 'infrastructure', 'other'];

    /** Why an owner closes their store (optional; for Umar Techy to learn from). */
    public const CLOSURE_REASONS = ['not_selling', 'too_expensive', 'missing_features', 'moving_platform', 'temporary_break', 'other', 'platform_request'];

    public function __construct(
        private readonly ConfigService $config,
        private readonly AuditLogger $audit,
        private readonly RecordsOutboxEvents $outbox,
        private readonly SubscriptionLifecycleService $subscriptions,
    ) {}

    public function retentionDays(): int
    {
        return max(90, (int) $this->config->get('stores.closure_retention_days')); // owner decision 5: never shorter
    }

    /**
     * The owner (or Umar Techy on their behalf) closes the store: the
     * storefront closes at once, billing stops (issued invoices stay due —
     * Module 29 §159), the admin turns read-only, and the data is kept for
     * the retention period, in which the owner can export it and reopen.
     */
    public function close(Store $store, ?string $reason, ?string $note, User $actor, bool $byPlatform): Store
    {
        if ($reason !== null && ! in_array($reason, self::CLOSURE_REASONS, true)) {
            throw new StoreLifecycleException('invalid_reason', 'Choose one of the listed reasons.');
        }

        return $this->transition($store, 'close', StoreStatus::Cancelled, $byPlatform ? 'platform' : 'owner', $actor, $reason, $note, function (Store $locked, StoreStatus $from) {
            $locked->forceFill([
                // A suspended store keeps what it was before the suspension, so reopening never lifts a suspension's reason silently.
                'status_before_hold' => $from === StoreStatus::Suspended ? $locked->status_before_hold : $from->value,
                'closed_at' => now(),
                'retention_ends_at' => now()->addDays($this->retentionDays()),
                'retention_reminded_at' => null,
            ]);
            $subscription = $this->subscription($locked);
            if ($subscription !== null && in_array($subscription->status, [SubscriptionStatus::Trialing, SubscriptionStatus::Active, SubscriptionStatus::PastDue, SubscriptionStatus::GracePeriod, SubscriptionStatus::Pending, SubscriptionStatus::Suspended], true)) {
                $this->subscriptions->transitionLocked($this->lockSubscription($subscription), SubscriptionStatus::Cancelled, 'subscription_cancelled', 'store_closed',
                    ['cancel_at_period_end' => false, 'cancellation_requested_at' => now()]);
            }
        });
    }

    /** Reopen within the retention period (§35): the store returns to what it was; billing restarts from today (Module 04 §38). */
    public function reopen(Store $store, User $actor, bool $byPlatform): Store
    {
        return $this->transition($store, 'reopen', null, $byPlatform ? 'platform' : 'owner', $actor, null, null, function (Store $locked) {
            if ($locked->retention_ends_at !== null && $locked->retention_ends_at->isPast()) {
                throw new StoreLifecycleException('retention_over', 'The time to reopen this store has passed. Contact the Umar Techy team.');
            }
            if ($locked->suspended_at !== null) {
                throw new StoreLifecycleException('suspended', 'This store was suspended by Umar Techy. Contact the Umar Techy team to reopen it.');
            }
            $subscription = $this->subscription($locked);
            if ($subscription === null) {
                throw new StoreLifecycleException('no_subscription', 'This store has no package. Contact the Umar Techy team to reopen it.');
            }
            $locked->forceFill(['closed_at' => null, 'closure_reason' => null, 'retention_ends_at' => null, 'retention_reminded_at' => null]);
            if (! $subscription->status->grantsAccess()) {
                $this->subscriptions->reactivate($locked, 'store_reopened'); // its own transaction nests inside this one
            }
        });
    }

    /** Umar Techy restricts a store (Module 03 §33): reason, actor, time, audit; storefront closed, admin read-only. */
    public function suspend(Store $store, string $reason, string $note, User $actor): Store
    {
        if (! in_array($reason, self::SUSPENSION_REASONS, true)) {
            throw new StoreLifecycleException('invalid_reason', 'Choose one of the listed reasons.');
        }

        return $this->transition($store, 'suspend', StoreStatus::Suspended, 'platform', $actor, $reason, $note, function (Store $locked, StoreStatus $from) use ($reason, $note) {
            $locked->forceFill([
                'status_before_hold' => $from === StoreStatus::Cancelled ? StoreStatus::Cancelled->value : $from->value,
                'suspended_at' => now(),
                'suspension_reason' => $reason,
                'suspension_note' => $note,
                // Owner decision 5: kept 90 days after a suspension too; past that, Umar Techy may archive it.
                'retention_ends_at' => $from === StoreStatus::Cancelled ? $locked->retention_ends_at : now()->addDays($this->retentionDays()),
            ]);
        });
    }

    /**
     * Lift a suspension (Module 03 §34): the subscription must still allow
     * access — or be reactivated with it, on purpose — and the store goes
     * back to where it was.
     */
    public function reactivate(Store $store, string $note, User $actor, bool $alsoSubscription): Store
    {
        return $this->transition($store, 'reactivate', null, 'platform', $actor, null, $note, function (Store $locked) use ($alsoSubscription) {
            $subscription = $this->subscription($locked);
            $returnsTo = StoreStatus::tryFrom((string) $locked->status_before_hold) ?? StoreStatus::Active;
            if ($returnsTo !== StoreStatus::Cancelled && ($subscription === null || ! $subscription->status->grantsAccess())) {
                if (! $alsoSubscription || $subscription === null) {
                    throw new StoreLifecycleException('subscription_inactive', 'The store\'s subscription does not allow access. Reactivate the subscription too, or change the package first.');
                }
                $this->subscriptions->reactivate($locked, 'store_reactivated');
            }
            $locked->forceFill(['suspended_at' => null, 'suspension_reason' => null, 'suspension_note' => null,
                'retention_ends_at' => $returnsTo === StoreStatus::Cancelled ? $locked->retention_ends_at : null]);
        });
    }

    /**
     * Past the retention period the store is archived (§36): kept for
     * records, not operational, and the owner can no longer reopen it.
     * Closed stores move by themselves (stores:lifecycle); a suspended one
     * only when Umar Techy decides.
     */
    public function archive(Store $store, ?User $actor, ?string $note = null): Store
    {
        return $this->transition($store, 'archive', StoreStatus::Archived, $actor === null ? 'system' : 'platform', $actor, null, $note, function (Store $locked) {
            if ($locked->retention_ends_at === null || $locked->retention_ends_at->isFuture()) {
                throw new StoreLifecycleException('retention_not_over', 'The store is still in its retention period (until '.$locked->retention_ends_at?->toDateString().').');
            }
            $locked->forceFill(['archived_at' => now()]);
        });
    }

    /** Bring an archived store back to "closed" with a new retention period, so its owner can export or reopen. */
    public function restore(Store $store, string $note, User $actor): Store
    {
        return $this->transition($store, 'restore', StoreStatus::Cancelled, 'platform', $actor, null, $note, function (Store $locked) {
            $locked->forceFill(['archived_at' => null, 'suspended_at' => null, 'suspension_reason' => null, 'suspension_note' => null,
                'closed_at' => $locked->closed_at ?? now(), 'retention_ends_at' => now()->addDays($this->retentionDays()), 'retention_reminded_at' => null]);
        });
    }

    /** Module 30 §140 "LEGAL/RETENTION CHECK": while on hold, nothing of the store can be deleted. */
    public function setLegalHold(Store $store, bool $hold, string $reason, User $actor): Store
    {
        return DB::transaction(function () use ($store, $hold, $reason, $actor) {
            $locked = Store::query()->withTrashed()->lockForUpdate()->findOrFail($store->id);
            if ($hold && StoreDeletion::query()->where('store_id', $locked->id)->whereIn('status', StoreDeletion::OPEN)->exists()) {
                throw new StoreLifecycleException('deletion_open', 'Cancel the scheduled deletion first.');
            }
            $locked->forceFill(['legal_hold' => $hold, 'legal_hold_reason' => $hold ? $reason : null])->save();
            $this->event($locked, $hold ? 'legal_hold_set' : 'legal_hold_lifted', $locked->status, $locked->status, 'platform', $actor, null, $reason);

            return $locked;
        });
    }

    /**
     * Request a permanent deletion (§37; Module 30 §140): only for an
     * archived store, with no legal hold and no unpaid invoice. It runs
     * after the cooling period and can be cancelled until it starts.
     *
     * @param array<string, int> $plan rows per table that would be removed (StorePurger::plan)
     */
    public function requestDeletion(Store $store, string $reason, array $plan, User $actor): StoreDeletion
    {
        return DB::transaction(function () use ($store, $reason, $plan, $actor) {
            $locked = Store::query()->withTrashed()->lockForUpdate()->findOrFail($store->id);
            $this->assertAllowed($locked, 'request_deletion');
            foreach ($this->deletionBlockers($locked) as $code => $message) {
                throw new StoreLifecycleException($code, $message);
            }
            $deletion = StoreDeletion::query()->create([
                'public_id' => (string) Str::ulid(), 'store_id' => $locked->id, 'status' => StoreDeletion::SCHEDULED, 'reason' => $reason,
                'requested_by' => $actor->id, 'not_before' => now()->addHours((int) $this->config->get('stores.deletion_cooling_hours')), 'plan' => $plan,
            ]);
            $this->event($locked, 'deletion_requested', $locked->status, $locked->status, 'platform', $actor, null, $reason);
            $this->audit->record('store.deletion_requested', ['deletion' => $deletion->public_id, 'not_before' => $deletion->not_before->toIso8601String(), 'rows' => array_sum($plan)], $locked, $locked->id, $actor);
            app(\App\Domain\Security\Services\SecurityMonitor::class)->record('store_deletion_requested', $actor, "{$locked->name} ({$locked->slug})", $locked->id, ['deletion' => $deletion->public_id]); // Phase B52
            $this->outbox->recordEventFor($locked->id, 'store.lifecycle_changed', ['store_id' => $locked->id, 'action' => 'deletion_requested', 'deletion_id' => $deletion->id], "store:{$locked->id}:deletion:{$deletion->public_id}:requested");

            return $deletion;
        });
    }

    public function cancelDeletion(StoreDeletion $deletion, string $note, User $actor): StoreDeletion
    {
        return DB::transaction(function () use ($deletion, $note, $actor) {
            $locked = StoreDeletion::query()->lockForUpdate()->findOrFail($deletion->id);
            if ($locked->status !== StoreDeletion::SCHEDULED && $locked->status !== StoreDeletion::FAILED) {
                throw new StoreLifecycleException('deletion_running', 'The deletion has already started and cannot be cancelled.');
            }
            $store = Store::query()->withTrashed()->lockForUpdate()->findOrFail($locked->store_id);
            $locked->forceFill(['status' => StoreDeletion::CANCELLED, 'cancelled_by' => $actor->id, 'cancelled_at' => now()])->save();
            if ($store->status === StoreStatus::Deleting) { // a failed run left it half-way: it stays archived, nothing more is removed
                $store->forceFill(['status' => StoreStatus::Archived])->save();
            }
            $this->event($store, 'deletion_cancelled', $store->status, $store->status, 'platform', $actor, null, $note);
            $this->audit->record('store.deletion_cancelled', ['deletion' => $locked->public_id, 'note' => $note], $store, $store->id, $actor);

            return $locked;
        });
    }

    /** @return array<string, string> code => message; empty when deletion may go ahead */
    public function deletionBlockers(Store $store): array
    {
        $blockers = [];
        if ($store->status !== StoreStatus::Archived) {
            $blockers['not_archived'] = 'Only an archived store can be deleted.';
        }
        if ($store->legal_hold) {
            $blockers['legal_hold'] = 'The store is on legal hold: '.($store->legal_hold_reason ?? '').'.';
        }
        if ($store->retention_ends_at === null || $store->retention_ends_at->isFuture()) {
            $blockers['retention_not_over'] = 'The retention period has not ended.';
        }
        // Module 29 §160: an open invoice must be paid, voided or written off (uncollectible) before deletion.
        $unpaid = DB::table('invoices')->where('store_id', $store->id)->where('status', 'open')->count();
        if ($unpaid > 0) {
            $blockers['unpaid_invoices'] = "{$unpaid} invoice(s) are still open. Mark them paid, void or uncollectible in Platform billing first.";
        }
        if (StoreDeletion::query()->where('store_id', $store->id)->whereIn('status', StoreDeletion::OPEN)->exists()) {
            $blockers['deletion_open'] = 'A deletion is already scheduled.';
        }

        return $blockers;
    }

    /** Used by the deletion run: archived → deleting, then deleting → deleted. */
    public function markDeleting(Store $store): Store
    {
        return $this->transition($store, 'start_deletion', StoreStatus::Deleting, 'system', null, null, null, fn () => null);
    }

    public function markDeleted(Store $store): Store
    {
        return $this->transition($store, 'finish_deletion', StoreStatus::Deleted, 'system', null, null, null, function (Store $locked) {
            $locked->forceFill(['deleted_at' => now()]);
        });
    }

    /**
     * @param \Closure(Store, StoreStatus): mixed $apply changes on the locked store (and related rows) before it is saved
     */
    private function transition(Store $store, string $action, ?StoreStatus $to, string $actorType, ?User $actor, ?string $reason, ?string $note, \Closure $apply): Store
    {
        return DB::transaction(function () use ($store, $action, $to, $actorType, $actor, $reason, $note, $apply) {
            $locked = Store::query()->withTrashed()->lockForUpdate()->findOrFail($store->id);
            $this->assertAllowed($locked, $action);
            $from = $locked->status;
            $apply($locked, $from);
            $target = $to ?? (StoreStatus::tryFrom((string) $locked->status_before_hold) ?? StoreStatus::Active);
            if ($action === 'close' && $reason !== null) {
                $locked->closure_reason = $reason;
            }
            if (in_array($action, ['reopen', 'reactivate'], true)) {
                // Back where it was; a closed store whose suspension is lifted reopens later as an active store.
                $locked->status_before_hold = null;
            }
            $locked->status = $target;
            $locked->save();

            $eventId = $this->event($locked, $action, $from, $target, $actorType, $actor, $reason, $note);
            $this->audit->record("store.{$action}", array_filter(['from' => $from->value, 'to' => $target->value, 'reason' => $reason, 'note' => $note,
                'retention_ends_at' => $locked->retention_ends_at?->toIso8601String(), 'by' => $actorType]), $locked, $locked->id, $actor);
            if (! in_array($action, ['start_deletion', 'finish_deletion'], true)) { // the deletion run records its own, platform-level
                $this->outbox->recordEventFor($locked->id, 'store.lifecycle_changed', ['store_id' => $locked->id, 'action' => $action, 'from' => $from->value, 'to' => $target->value, 'event_id' => $eventId],
                    "store:{$locked->id}:lifecycle:{$eventId}");
            }

            return $locked;
        });
    }

    private function assertAllowed(Store $store, string $action): void
    {
        if (! in_array($store->status->value, self::TRANSITIONS[$action], true)) {
            throw new StoreLifecycleException('not_allowed', sprintf('A %s store cannot be %s.', str_replace('_', ' ', StoreLifecycle::label($store->status)), StoreLifecycle::actionLabel($action)));
        }
    }

    private function event(Store $store, string $action, StoreStatus $from, StoreStatus $to, string $actorType, ?User $actor, ?string $reason, ?string $note): int
    {
        return StoreLifecycleEvent::query()->create([
            'store_id' => $store->id, 'action' => $action, 'from_status' => $from->value, 'to_status' => $to->value,
            'reason' => $reason, 'note' => $note === null ? null : mb_substr($note, 0, 500), 'actor_type' => $actorType,
            'actor_user_id' => $actor?->id, 'request_id' => RequestId::current(),
        ])->id;
    }

    private function subscription(Store $store): ?Subscription
    {
        return Subscription::query()->withoutTenantScope()->where('store_id', $store->id)->orderByDesc('id')->first();
    }

    private function lockSubscription(Subscription $subscription): Subscription
    {
        return Subscription::query()->withoutTenantScope()->lockForUpdate()->findOrFail($subscription->id);
    }
}
