<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Module 29 — the scheduled billing run (`billing:run`, hourly). Each
 * subscription is processed in its own transaction under its own row
 * lock, so one store's failure is logged and skipped, never rolls back
 * or blocks another store's billing.
 */
final class BillingRunner
{
    public function __construct(private readonly SubscriptionBillingEngine $engine) {}

    /** @return array<string, int> action => count, plus "processed" and "failed" */
    public function run(): array
    {
        $summary = ['processed' => 0, 'failed' => 0];
        $horizon = now()->addDays((int) config('billing.issue_days_before'));
        $dunning = [SubscriptionStatus::PastDue->value, SubscriptionStatus::GracePeriod->value, SubscriptionStatus::Suspended->value];

        $ids = Subscription::query()->withoutTenantScope()
            ->whereIn('status', [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value, ...$dunning])
            ->whereNotNull('current_period_ends_at')
            ->where(fn ($q) => $q->where('current_period_ends_at', '<=', $horizon)->orWhereIn('status', $dunning))
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            try {
                $actions = DB::transaction(function () use ($id) {
                    $subscription = Subscription::query()->withoutTenantScope()->lockForUpdate()->find($id);

                    return $subscription !== null ? $this->engine->process($subscription) : [];
                });

                $summary['processed']++;

                foreach ($actions as $action) {
                    $summary[$action] = ($summary[$action] ?? 0) + 1;
                }
            } catch (\Throwable $e) {
                $summary['failed']++;
                Log::error('billing.run.subscription_failed', ['subscription_id' => $id, 'error' => $e->getMessage()]);
                report($e);
            }
        }

        return $summary;
    }
}
