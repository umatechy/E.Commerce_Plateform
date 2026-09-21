<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Http\Controllers;

use App\Domain\Analytics\Exceptions\InvalidDateRangeException;
use App\Domain\Analytics\Http\Requests\DateRangeRequest;
use App\Domain\Analytics\Policies\AnalyticsPolicy;
use App\Domain\Analytics\Services\DateRangeResolver;
use App\Domain\Analytics\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 22 §16-28/§38 "Report Types / Report Definitions". Financial-
 * shaped reports (sales, payments, promotions) require
 * `analytics.financial`; operational reports (products, customers,
 * shipping, marketing, notifications, inventory) require only
 * `analytics.view` (Module 22 §35-36).
 */
final class ReportController
{
    public function sales(DateRangeRequest $request, DateRangeResolver $dateRanges, ReportService $reports): JsonResponse
    {
        abort_unless(app(AnalyticsPolicy::class)->viewFinancial($request->user()), 403);

        return $this->respond($request, $dateRanges, fn ($start, $end) => $reports->salesReport($start, $end));
    }

    public function products(DateRangeRequest $request, DateRangeResolver $dateRanges, ReportService $reports): JsonResponse
    {
        abort_unless(app(AnalyticsPolicy::class)->view($request->user()), 403);

        try {
            [$start, $end] = $dateRanges->resolve($request->input('date_filter', 'this_month'), $request->input('start'), $request->input('end'));
        } catch (InvalidDateRangeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_date_range'], 422);
        }

        $sortBy = in_array($request->input('sort_by'), ['revenue', 'quantity'], true) ? $request->input('sort_by') : 'revenue';

        return response()->json(['data' => $reports->productsReport($start, $end, $sortBy)]);
    }

    public function customers(DateRangeRequest $request, DateRangeResolver $dateRanges, ReportService $reports): JsonResponse
    {
        abort_unless(app(AnalyticsPolicy::class)->view($request->user()), 403);

        return $this->respond($request, $dateRanges, fn ($start, $end) => $reports->customersReport($start, $end));
    }

    public function payments(DateRangeRequest $request, DateRangeResolver $dateRanges, ReportService $reports): JsonResponse
    {
        abort_unless(app(AnalyticsPolicy::class)->viewFinancial($request->user()), 403);

        return $this->respond($request, $dateRanges, fn ($start, $end) => $reports->paymentsReport($start, $end));
    }

    public function shipping(DateRangeRequest $request, DateRangeResolver $dateRanges, ReportService $reports): JsonResponse
    {
        abort_unless(app(AnalyticsPolicy::class)->view($request->user()), 403);

        return $this->respond($request, $dateRanges, fn ($start, $end) => $reports->shippingReport($start, $end));
    }

    public function promotions(DateRangeRequest $request, DateRangeResolver $dateRanges, ReportService $reports): JsonResponse
    {
        abort_unless(app(AnalyticsPolicy::class)->viewFinancial($request->user()), 403);

        return $this->respond($request, $dateRanges, fn ($start, $end) => $reports->promotionsReport($start, $end));
    }

    public function marketing(DateRangeRequest $request, DateRangeResolver $dateRanges, ReportService $reports): JsonResponse
    {
        abort_unless(app(AnalyticsPolicy::class)->view($request->user()), 403);

        return $this->respond($request, $dateRanges, fn ($start, $end) => $reports->marketingReport($start, $end));
    }

    public function notifications(DateRangeRequest $request, DateRangeResolver $dateRanges, ReportService $reports): JsonResponse
    {
        abort_unless(app(AnalyticsPolicy::class)->view($request->user()), 403);

        return $this->respond($request, $dateRanges, fn ($start, $end) => $reports->notificationsReport($start, $end));
    }

    public function inventory(Request $request, ReportService $reports): JsonResponse
    {
        abort_unless(app(AnalyticsPolicy::class)->view($request->user()), 403);

        return response()->json(['data' => $reports->inventoryReport()]);
    }

    private function respond(DateRangeRequest $request, DateRangeResolver $dateRanges, \Closure $callback): JsonResponse
    {
        try {
            [$start, $end] = $dateRanges->resolve($request->input('date_filter', 'this_month'), $request->input('start'), $request->input('end'));
        } catch (InvalidDateRangeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_date_range'], 422);
        }

        return response()->json(['data' => $callback($start, $end)]);
    }
}
