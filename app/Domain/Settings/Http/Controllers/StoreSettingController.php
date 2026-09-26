<?php

declare(strict_types=1);

namespace App\Domain\Settings\Http\Controllers;

use App\Domain\Settings\Exceptions\InvalidSettingValueException;
use App\Domain\Settings\Exceptions\SettingRevisionNotFoundException;
use App\Domain\Settings\Exceptions\SettingScopeMismatchException;
use App\Domain\Settings\Exceptions\UnknownSettingKeyException;
use App\Domain\Settings\Http\Requests\UpdateSettingRequest;
use App\Domain\Settings\Http\Resources\SettingResource;
use App\Domain\Settings\Http\Resources\SettingRevisionResource;
use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Policies\SettingPolicy;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Settings\Services\SettingRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Staff-facing, STORE-scope settings API (Module 33 §19/§41 "Store/
 * Tenant Settings / Tenant Admin Integration"). Every method:
 * authenticated (staff.principal, via route group), authorized
 * (SettingPolicy), and structurally cannot touch a platform-scope key
 * at all — every write goes through ConfigService::set() with
 * `SettingScope::Store` as the expected scope, which rejects any
 * platform-scope key before persistence (Module 33 §8 "a tenant
 * setting must never become a platform-global setting accidentally").
 */
final class StoreSettingController
{
    public function index(Request $request, ConfigService $config): JsonResponse
    {
        abort_unless(app(SettingPolicy::class)->viewStore($request->user()), 403);

        $resources = collect(SettingRegistry::all())
            ->filter(fn ($definition) => $definition->scope === SettingScope::Store)
            ->map(fn ($definition) => new SettingResource($definition, $config->get($definition->key)))
            ->values();

        return response()->json(['data' => $resources]);
    }

    public function show(Request $request, string $key, ConfigService $config): JsonResponse
    {
        abort_unless(app(SettingPolicy::class)->viewStore($request->user()), 403);

        try {
            $definition = SettingRegistry::find($key);
            if ($definition === null || $definition->scope !== SettingScope::Store) {
                throw new UnknownSettingKeyException($key);
            }

            return response()->json(['data' => new SettingResource($definition, $config->get($key))]);
        } catch (UnknownSettingKeyException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'unknown_setting'], 404);
        }
    }

    public function update(UpdateSettingRequest $request, string $key, ConfigService $config): JsonResponse
    {
        abort_unless(app(SettingPolicy::class)->manageStore($request->user()), 403);

        try {
            $config->set($key, $request->input('value'), SettingScope::Store, $request->user()->id, $request->input('reason'));
            $definition = SettingRegistry::find($key);

            return response()->json(['data' => new SettingResource($definition, $config->get($key))]);
        } catch (UnknownSettingKeyException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'unknown_setting'], 404);
        } catch (SettingScopeMismatchException|InvalidSettingValueException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_setting'], 422);
        }
    }

    public function history(Request $request, string $key, ConfigService $config): AnonymousResourceCollection|JsonResponse
    {
        abort_unless(app(SettingPolicy::class)->viewStore($request->user()), 403);

        try {
            return SettingRevisionResource::collection($config->history($key));
        } catch (UnknownSettingKeyException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'unknown_setting'], 404);
        }
    }

    public function rollback(Request $request, int $revisionId, ConfigService $config): JsonResponse
    {
        abort_unless(app(SettingPolicy::class)->manageStore($request->user()), 403);

        try {
            $config->rollbackTo($revisionId, $request->user()->id);
        } catch (SettingRevisionNotFoundException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'revision_not_found'], 404);
        }

        return response()->json(status: 204);
    }
}
