<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Http\Controllers;

use App\Domain\Analytics\Exceptions\InvalidDateRangeException;
use App\Domain\Analytics\Http\Requests\DateRangeRequest;
use App\Domain\Analytics\Policies\AnalyticsPolicy;
use App\Domain\Analytics\Services\DashboardService;
use App\Domain\Analytics\Services\DateRangeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Module 22 §10-11 "Store Dashboard". */
final class DashboardController
{
    public function summary(DateRangeRequest $request, DateRangeResolver $dateRanges, DashboardService $dashboard): JsonResponse
    {
        abort_unless(app(AnalyticsPolicy::class)->view($request->user()), 403);

        try {
            [$start, $end] = $dateRanges->resolve($request->input('date_filter', 'this_month'), $request->input('start'), $request->input('end'));
        } catch (InvalidDateRangeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_date_range'], 422);
        }

        // Module 22 §36 "Sensitive Reports" — revenue/financial figures
        // require the STRICTER analytics.financial permission; a staff
        // member with only analytics.view sees the dashboard shape
        // without financial figures (never a client-side-only hide).
        if (! app(AnalyticsPolicy::class)->viewFinancial($request->user())) {
            $data = $request->boolean('compare') ? $dashboard->summaryWithComparison($start, $end) : $dashboard->summary($start, $end);
            $data = $this->stripFinancials($data);

            return response()->json(['data' => $data]);
        }

        $data = $request->boolean('compare') ? $dashboard->summaryWithComparison($start, $end) : $dashboard->summary($start, $end);

        return response()->json(['data' => $data]);
    }

    private function stripFinancials(array $data): array
    {
        $financialKeys = ['gross_sales_minor', 'discounts_minor', 'net_sales_minor', 'shipping_charged_minor', 'revenue_minor', 'collected_amount_minor', 'refunded_amount_minor', 'average_order_value_minor'];

        if (isset($data['current'])) {
            $data['current'] = collect($data['current'])->except($financialKeys)->all();
            $data['previous'] = collect($data['previous'])->except($financialKeys)->all();
            unset($data['revenue_change_percent']);

            return $data;
        }

        return collect($data)->except($financialKeys)->all();
    }
}
