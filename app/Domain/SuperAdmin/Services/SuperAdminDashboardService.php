<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Services;

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderStatus;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Packages\Models\SubscriptionStatus;
use App\Domain\Payments\Models\PaymentTransaction;
use App\Domain\Payments\Models\TransactionStatus;
use App\Domain\Payments\Models\TransactionType;
use App\Domain\Tenancy\Models\Store;

/**
 * Module 30 §8 "Platform Dashboard" — Architectural Decision (see
 * docs/development/b16-inspection-findings.md): a SEPARATE service
 * from B12's DashboardService, which is intentionally tenant-scoped.
 * Every query here explicitly bypasses the tenant scope
 * (withoutTenantScope()) to aggregate ACROSS every store — this is
 * the one legitimate reason to do so, exactly like B14's
 * DomainResolverService's own documented exception. Reuses B12's own
 * Metric Dictionary definitions (Revenue = grand_total_minor,
 * Collected Amount = payment_transactions) rather than inventing new
 * ones.
 */
final class SuperAdminDashboardService
{
    public function summary(): array
    {
        return [
            'stores' => [
                'total' => Store::query()->count(),
                'active_subscriptions' => Subscription::query()->withoutTenantScope()->where('status', SubscriptionStatus::Active->value)->count(),
                'trial_subscriptions' => Subscription::query()->withoutTenantScope()->where('status', SubscriptionStatus::Trialing->value)->count(),
                'suspended_subscriptions' => Subscription::query()->withoutTenantScope()->where('status', SubscriptionStatus::Suspended->value)->count(),
            ],
            'orders' => [
                'total_last_30_days' => Order::query()->withoutTenantScope()
                    ->where('created_at', '>=', now()->subDays(30))
                    ->where('status', '!=', OrderStatus::Cancelled->value)
                    ->count(),
                'revenue_minor_last_30_days' => (int) Order::query()->withoutTenantScope()
                    ->where('created_at', '>=', now()->subDays(30))
                    ->where('status', '!=', OrderStatus::Cancelled->value)
                    ->sum('grand_total_minor'),
            ],
            'payments' => [
                'collected_amount_minor_last_30_days' => (int) PaymentTransaction::query()->withoutTenantScope()
                    ->where('created_at', '>=', now()->subDays(30))
                    ->whereIn('type', [TransactionType::Sale, TransactionType::Capture])
                    ->where('status', TransactionStatus::Succeeded)
                    ->sum('amount_minor'),
                'failed_last_30_days' => PaymentTransaction::query()->withoutTenantScope()
                    ->where('created_at', '>=', now()->subDays(30))
                    ->where('status', TransactionStatus::Failed)
                    ->count(),
            ],
        ];
    }
}
