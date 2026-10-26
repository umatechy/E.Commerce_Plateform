<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Http\Controllers;

use App\Domain\Analytics\Exceptions\InvalidDateRangeException;
use App\Domain\Analytics\Http\Requests\DateRangeRequest;
use App\Domain\Analytics\Policies\AnalyticsPolicy;
use App\Domain\Analytics\Services\AdvancedAnalyticsService;
use App\Domain\Analytics\Services\DateRangeResolver;
use App\Domain\Packages\Services\EntitlementService;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Phase P3 — Reports → Analytics (Premium, `advanced_analytics.enabled`;
 * Module 04 §8, Module 22 Rule 24). `analytics.view` to look; the money
 * figures need `analytics.financial` and are null without it (Module 22 §35).
 * Everything is aggregated: no customer's name or address appears (§20).
 */
final class AdvancedAnalyticsController
{
    public const FEATURE = 'advanced_analytics.enabled';

    public function overview(DateRangeRequest $request, DateRangeResolver $dateRanges, AdvancedAnalyticsService $analytics): JsonResponse
    {
        $policy = app(AnalyticsPolicy::class);
        abort_unless($policy->view($request->user()), 403);
        if (! app(EntitlementService::class)->hasFeature(self::FEATURE)) {
            return response()->json(['message' => 'Advanced analytics are part of the Premium package.', 'code' => 'feature_not_entitled'], 403);
        }
        $compare = $request->input('compare_with', 'previous_period');
        if (! in_array($compare, ['previous_period', 'previous_year'], true)) {
            return response()->json(['message' => 'Compare with the previous period or the same period last year.', 'code' => 'invalid_comparison'], 422);
        }

        try {
            [$start, $end] = $dateRanges->resolve($request->input('date_filter', 'last_30_days'), $request->input('start'), $request->input('end'));
        } catch (InvalidDateRangeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_date_range'], 422);
        }
        // §13: the comparison period is named with the result, so no figure is read against the wrong one.
        [$compareStart, $compareEnd] = $compare === 'previous_year'
            ? [$start->copy()->subYearNoOverflow(), $end->copy()->subYearNoOverflow()]
            : $dateRanges->previousPeriod($start, $end);
        $currency = strtoupper((string) app(ConfigService::class)->get('store.default_currency'));

        return response()->json(['data' => [
            'period' => ['start' => $start->toIso8601String(), 'end' => $end->toIso8601String()],
            'comparison' => ['kind' => $compare, 'start' => $compareStart->toIso8601String(), 'end' => $compareEnd->toIso8601String()],
            'financial' => $policy->viewFinancial($request->user()),
            ...$analytics->overview(app(TenantContext::class)->storeId(), $currency, $start, $end, $compareStart, $compareEnd, $policy->viewFinancial($request->user())),
        ]]);
    }
}
