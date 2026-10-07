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
    /**
     * Phase B46: tax settings are changed only on the Tax page (TaxController,
     * tax.manage, "a rate before tax is on"); this generic endpoint neither
     * lists nor changes them, so neither check can be gone round.
     */
    private const OWN_PAGE_PREFIXES = ['tax.'];

    private function hasOwnPage(string $key): bool
    {
        return \Illuminate\Support\Str::startsWith($key, self::OWN_PAGE_PREFIXES);
    }

    private function ownPageResponse(): JsonResponse
    {
        return response()->json(['message' => 'Tax settings are changed on the Tax page.', 'code' => 'managed_on_tax_page'], 403);
    }

    public function index(Request $request, ConfigService $config): JsonResponse
    {
        abort_unless(app(SettingPolicy::class)->viewStore($request->user()), 403);

        $resources = collect(SettingRegistry::all())
            ->filter(fn ($definition) => $definition->scope === SettingScope::Store && ! $this->hasOwnPage($definition->key))
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
        if ($this->hasOwnPage($key)) {
            return $this->ownPageResponse();
        }

        try {
            $config->set($key, $request->input('value'), SettingScope::Store, $request->user()->id, $request->input('reason'));
            $definition = SettingRegistry::find($key);

            return response()->json(['data' => new SettingResource($definition, $config->get($key))]);
        } catch (UnknownSettingKeyException|SettingScopeMismatchException $e) {
            // A platform-scope key is not addressable through the store
            // endpoint at all: answer exactly like an unknown key, so the
            // response never reveals which platform settings exist.
            return response()->json(['message' => (new UnknownSettingKeyException($key))->getMessage(), 'code' => 'unknown_setting'], 404);
        } catch (InvalidSettingValueException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_setting'], 422);
        }
    }

    public function history(Request $request, string $key, ConfigService $config): AnonymousResourceCollection|JsonResponse
    {
        abort_unless(app(SettingPolicy::class)->viewStore($request->user()), 403);

        // Phase B32 security fix: only store settings have a store history.
        // Before, any key was accepted, so a store's staff could read the
        // history of platform settings (e.g. alert recipient addresses).
        $definition = SettingRegistry::find($key);
        if ($definition === null || $definition->scope !== SettingScope::Store) {
            return response()->json(['message' => (new UnknownSettingKeyException($key))->getMessage(), 'code' => 'unknown_setting'], 404);
        }

        try {
            return SettingRevisionResource::collection($config->history($key));
        } catch (UnknownSettingKeyException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'unknown_setting'], 404);
        }
    }

    public function rollback(Request $request, int $revisionId, ConfigService $config): JsonResponse
    {
        abort_unless(app(SettingPolicy::class)->manageStore($request->user()), 403);
        $revisionKey = \App\Domain\Settings\Models\SettingRevision::query()->whereKey($revisionId)->value('key');
        if (is_string($revisionKey) && $this->hasOwnPage($revisionKey)) {
            return $this->ownPageResponse();
        }

        try {
            $config->rollbackTo($revisionId, $request->user()->id, SettingScope::Store);
        } catch (SettingRevisionNotFoundException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'revision_not_found'], 404);
        }

        return response()->json(status: 204);
    }
}
