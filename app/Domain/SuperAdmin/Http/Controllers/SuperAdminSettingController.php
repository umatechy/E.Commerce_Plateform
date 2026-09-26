<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\Settings\Exceptions\InvalidSettingValueException;
use App\Domain\Settings\Exceptions\SettingScopeMismatchException;
use App\Domain\Settings\Exceptions\UnknownSettingKeyException;
use App\Domain\Settings\Http\Resources\SettingResource;
use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Settings\Services\SettingRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Module 33 §18/§40 "Platform-Global Settings / Super Admin
 * Integration" (Phase B17). Sits in the SAME 'super_admin.platform'
 * route group B16 already established for platform-global,
 * no-target-store actions (Package/Theme catalog management,
 * dashboard) — reuses that authorization/audit boundary exactly,
 * never a new Super Admin gate.
 */
final class SuperAdminSettingController
{
    public function index(ConfigService $config): JsonResponse
    {
        $resources = collect(SettingRegistry::all())
            ->filter(fn ($definition) => $definition->scope === SettingScope::Platform)
            ->map(fn ($definition) => new SettingResource($definition, $config->get($definition->key)))
            ->values();

        return response()->json(['data' => $resources]);
    }

    public function update(Request $request, string $key, ConfigService $config): JsonResponse
    {
        $request->validate(['value' => ['required'], 'reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        try {
            $config->set($key, $request->input('value'), SettingScope::Platform, $request->user()->id, $request->input('reason'));
        } catch (UnknownSettingKeyException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'unknown_setting'], 404);
        } catch (SettingScopeMismatchException|InvalidSettingValueException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_setting'], 422);
        }

        Log::channel('audit')->info('super_admin.setting.updated', [
            'acting_super_admin_id' => $request->user()->id, 'key' => $key,
        ]);

        $definition = SettingRegistry::find($key);

        return response()->json(['data' => new SettingResource($definition, $config->get($key))]);
    }
}
