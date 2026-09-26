<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\SuperAdmin\Services\SuperAdminDashboardService;
use Illuminate\Http\JsonResponse;

final class SuperAdminDashboardController
{
    public function show(SuperAdminDashboardService $dashboard): JsonResponse
    {
        return response()->json(['data' => $dashboard->summary()]);
    }
}
