<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\DeveloperPlatform\Services\ApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Module 31 §51 "Developer API Administration" — reuses B16's existing
 * platform-global route group unchanged, same pattern as
 * SuperAdminThemeController/SuperAdminPackageController. Read-only
 * visibility + suspend (the one documented mutation Module 31 names) —
 * every mutation goes through ApplicationService, never a direct
 * database write from this controller.
 */
final class SuperAdminDeveloperPlatformController
{
    public function index(): JsonResponse
    {
        $applications = DeveloperApplication::query()->withoutTenantScope()->with('apiKeys')->paginate(50);

        return response()->json(['data' => $applications]);
    }

    public function suspend(Request $request, DeveloperApplication $application, ApplicationService $applications): JsonResponse
    {
        $applications->suspend($application);

        Log::channel('audit')->info('super_admin.developer_application.suspended', [
            'acting_super_admin_id' => $request->user()->id, 'application_id' => $application->id, 'store_id' => $application->store_id,
        ]);

        return response()->json(status: 204);
    }
}
