<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Models\Attribute;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Services\StarterTemplateRegistry;
use App\Domain\Catalog\Services\StarterTemplateService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreStatus;
use App\Domain\Tenancy\Support\TenantContext;
use App\Domain\Theme\Policies\ThemePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Phase B45 — Module 07 §105 "Store setup experience": the store's staff
 * look at the starter templates offered to stores and apply one (Start
 * empty, Import existing catalogue and the template are the three choices;
 * the CSV import is the existing product import).
 *
 * Looking needs category or attribute access; applying needs both (it
 * writes categories and attributes), plus brands access for brands and
 * theme publishing for the theme — a template never does more than the
 * person could do by hand. Templates Umar Techy has switched off, or keeps
 * for its own staff, answer 404 here.
 */
final class StarterTemplateController
{
    public function index(Request $request, TenantContext $context, StarterTemplateService $service, StarterTemplateRegistry $registry): JsonResponse
    {
        $this->authorizeView($request);
        $store = Store::query()->findOrFail($context->storeId());

        return response()->json([
            'data' => array_values(array_map(fn (array $e) => [
                'key' => $e['key'], 'name' => $e['name'], 'version' => $e['version'], 'summary' => $e['summary'], 'source' => $e['source'],
                'categories' => count($e['definition']['categories'] ?? []), 'attributes' => count($e['definition']['attributes'] ?? []), 'brands' => count($e['definition']['brands'] ?? []),
            ], $registry->offered())),
            'recommended' => $registry->forBusinessCategory($store->business_category),
            'history' => $service->history(),
        ]);
    }

    public function show(Request $request, string $key, TenantContext $context, StarterTemplateService $service, StarterTemplateRegistry $registry): JsonResponse
    {
        $this->authorizeView($request);
        $entry = $registry->usable($key, byStoreStaff: true) ?? abort(404);
        $store = Store::query()->findOrFail($context->storeId());

        // store_live: a live store gets the theme in its draft, not published (the page says so).
        return response()->json(['data' => [...$service->preview($entry), 'store_live' => $store->status === StoreStatus::Active]]);
    }

    public function apply(Request $request, string $key, TenantContext $context, StarterTemplateService $service, StarterTemplateRegistry $registry): JsonResponse
    {
        $entry = $registry->usable($key, byStoreStaff: true) ?? abort(404);
        $user = $request->user();
        $options = $request->validate(['brands' => ['sometimes', 'boolean'], 'theme' => ['sometimes', 'boolean'], 'default_sort' => ['sometimes', 'boolean']]);
        Gate::forUser($user)->authorize('manage', Category::class);
        Gate::forUser($user)->authorize('manage', Attribute::class);
        if ($options['brands'] ?? false) {
            Gate::forUser($user)->authorize('manage', Brand::class);
        }
        if ($options['theme'] ?? false) {
            abort_unless(app(ThemePolicy::class)->publish($user), 403);
        }

        $summary = $service->apply(Store::query()->findOrFail($context->storeId()), $entry, $user, $options);

        return response()->json(['data' => $summary]);
    }

    private function authorizeView(Request $request): void
    {
        $user = $request->user();
        abort_unless(Gate::forUser($user)->allows('manage', Category::class) || Gate::forUser($user)->allows('manage', Attribute::class), 403);
    }
}
