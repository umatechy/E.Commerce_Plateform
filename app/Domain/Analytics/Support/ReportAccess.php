<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Support;

use App\Domain\Analytics\Policies\AnalyticsPolicy;
use App\Domain\Identity\Models\User;
use App\Domain\Packages\Services\EntitlementService;

/**
 * Phase P3 (Module 04 §8 "Advanced Reports: Business, Premium"; Module 22
 * §59–60): who may see each report.
 *
 * - Every package has the dashboard and the core reports: sales, products,
 *   customers and stock (`analytics.basic`).
 * - The deeper breakdowns — payments, delivery, discounts, campaigns,
 *   customer messages, categories & brands — are the advanced reports
 *   (`reports.advanced`, Business and Premium).
 * - Money-shaped reports also need `analytics.financial` (Module 22 §35),
 *   whether they are viewed or exported.
 */
final class ReportAccess
{
    public const ADVANCED_FEATURE = 'reports.advanced';

    public const REFUSAL = 'This report is part of the advanced reports of the Business and Premium packages.';

    /** @var list<string> */
    public const ADVANCED = ['payments', 'shipping', 'promotions', 'marketing', 'notifications', 'categories'];

    /** @var list<string> */
    public const FINANCIAL = ['sales', 'payments', 'promotions', 'categories'];

    public static function isAdvanced(string $report): bool
    {
        return in_array($report, self::ADVANCED, true);
    }

    public static function entitled(string $report): bool
    {
        return ! self::isAdvanced($report) || app(EntitlementService::class)->hasFeature(self::ADVANCED_FEATURE);
    }

    public static function permitted(User $user, string $report): bool
    {
        $policy = app(AnalyticsPolicy::class);

        return in_array($report, self::FINANCIAL, true) ? $policy->viewFinancial($user) : $policy->view($user);
    }
}
