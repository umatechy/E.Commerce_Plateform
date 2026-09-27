<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\Infrastructure\Services\InfrastructureHealthService;
use Illuminate\Http\JsonResponse;

/**
 * Module 20 Phase 47 "Super Admin" — reuses B16's existing
 * super_admin.platform route group unchanged. Distinct from the public
 * /api/v1/public/health endpoint only in that a future, more detailed
 * check (e.g. real latency figures) could safely be added here without
 * being exposed publicly — B20 keeps both call sites identical for now
 * (no additional sensitive detail exists yet to gate), but the
 * boundary is established for that future extension.
 */
final class SuperAdminInfrastructureController
{
    public function health(InfrastructureHealthService $health): JsonResponse
    {
        return response()->json(['data' => $health->check()]);
    }
}
