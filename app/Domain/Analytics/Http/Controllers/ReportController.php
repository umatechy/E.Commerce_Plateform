<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Http\Controllers;

use App\Domain\Analytics\Exceptions\InvalidDateRangeException;
use App\Domain\Analytics\Http\Requests\DateRangeRequest;
use App\Domain\Analytics\Services\DateRangeResolver;
use App\Domain\Analytics\Services\ReportService;
use App\Domain\Analytics\Support\ReportAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Module 22 §16-28/§38 "Report Types / Report Definitions". Financial-
 * shaped reports (sales, payments, promotions, categories) require
 * `analytics.financial`; operational reports (products, customers,
 * shipping, marketing, notifications, inventory) require only
 * `analytics.view` (Module 22 §35-36).
 *
 * Phase P3: the advanced reports need `reports.advanced` in the package
 * (ReportAccess); the refusal says so with `feature_not_entitled`.
 */
final class ReportController
{
    public function sales(DateRangeRequest $request, DateRangeResolver $dateRanges, ReportService $reports): JsonResponse
    {
        return $this->refused($request, 'sales') ?? $this->respond($request, $dateRanges, fn ($start, $end) => $reports->salesReport($start, $end));
    }

    public function products(DateRangeRequest $request, DateRangeResolver $dateRanges, ReportService $reports): JsonResponse
    {
        if ($refused = $this->refused($request, 'products')) {
            return $refused;
        }

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
        return $this->refused($request, 'customers') ?? $this->respond($request, $dateRanges, fn ($start, $end) => $reports->customersReport($start, $end));
    }

    public function payments(DateRangeRequest $request, DateRangeResolver $dateRanges, ReportService $reports): JsonResponse
    {
        return $this->refused($request, 'payments') ?? $this->respond($request, $dateRanges, fn ($start, $end) => $reports->paymentsReport($start, $end));
    }

    public function shipping(DateRangeRequest $request, DateRangeResolver $dateRanges, ReportService $reports): JsonResponse
    {
        return $this->refused($request, 'shipping') ?? $this->respond($request, $dateRanges, fn ($start, $end) => $reports->shippingReport($start, $end));
    }

    public function promotions(DateRangeRequest $request, DateRangeResolver $dateRanges, ReportService $reports): JsonResponse
    {
        return $this->refused($request, 'promotions') ?? $this->respond($request, $dateRanges, fn ($start, $end) => $reports->promotionsReport($start, $end));
    }

    public function marketing(DateRangeRequest $request, DateRangeResolver $dateRanges, ReportService $reports): JsonResponse
    {
        return $this->refused($request, 'marketing') ?? $this->respond($request, $dateRanges, fn ($start, $end) => $reports->marketingReport($start, $end));
    }

    public function notifications(DateRangeRequest $request, DateRangeResolver $dateRanges, ReportService $reports): JsonResponse
    {
        return $this->refused($request, 'notifications') ?? $this->respond($request, $dateRanges, fn ($start, $end) => $reports->notificationsReport($start, $end));
    }

    /** Phase P3 — Module 22 §18 "Category / Brand Analytics" (advanced, financial). */
    public function categories(DateRangeRequest $request, DateRangeResolver $dateRanges, ReportService $reports): JsonResponse
    {
        return $this->refused($request, 'categories') ?? $this->respond($request, $dateRanges, fn ($start, $end) => $reports->categoriesReport($start, $end));
    }

    public function inventory(Request $request, ReportService $reports): JsonResponse
    {
        return $this->refused($request, 'inventory') ?? response()->json(['data' => $reports->inventoryReport()]);
    }

    /** The permission first (403), then the package (403 feature_not_entitled). */
    private function refused(Request $request, string $report): ?JsonResponse
    {
        abort_unless(ReportAccess::permitted($request->user(), $report), 403);
        if (! ReportAccess::entitled($report)) {
            return response()->json(['message' => ReportAccess::REFUSAL, 'code' => 'feature_not_entitled'], 403);
        }

        return null;
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
