<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Http\Controllers;

use App\Domain\Infrastructure\Services\InfrastructureHealthService;
use Illuminate\Http\JsonResponse;

/**
 * Public, unauthenticated (Module 20 Phase 30) — deliberately minimal:
 * overall status + per-check ok/failed only, never a raw exception
 * message, hostname, or credential. A load balancer/uptime monitor is
 * the intended caller.
 */
final class HealthController
{
    public function show(InfrastructureHealthService $health): JsonResponse
    {
        $result = $health->check();

        return response()->json($result, $result['status'] === 'ok' ? 200 : 503);
    }
}
