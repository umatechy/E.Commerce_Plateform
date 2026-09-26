<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\Theme\Models\Theme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Module 17 §7/Module 30 §21 "Theme Management" (Phase B16) — Theme
 * catalog administration, mirrors SuperAdminPackageController exactly
 * (a Theme is a platform-global catalog row, same shape as Package).
 * Sits under the 'super_admin.platform' group (no target store —
 * see docs/development/b16-inspection-findings.md "Critical Bug
 * Found"), never the per-store impersonation group.
 */
final class SuperAdminThemeController
{
    public function index(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['data' => Theme::query()->get()]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:64', 'unique:themes,key'],
            'name' => ['required', 'string', 'max:255'],
            'version' => ['required', 'string', 'max:16'],
        ]);

        $theme = Theme::query()->create($data);

        Log::channel('audit')->info('super_admin.theme.created', [
            'acting_super_admin_id' => $request->user()->id, 'theme_id' => $theme->id, 'key' => $theme->key,
        ]);

        return response()->json(['data' => $theme], 201);
    }

    public function update(Request $request, Theme $theme): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'version' => ['sometimes', 'string', 'max:16'],
            'status' => ['sometimes', 'in:active,deprecated'],
        ]);

        $before = $theme->only(['name', 'version', 'status']);
        $theme->update($data);

        Log::channel('audit')->info('super_admin.theme.updated', [
            'acting_super_admin_id' => $request->user()->id, 'theme_id' => $theme->id,
            'before' => $before, 'after' => $theme->only(['name', 'version', 'status']),
        ]);

        return response()->json(['data' => $theme->fresh()]);
    }
}
