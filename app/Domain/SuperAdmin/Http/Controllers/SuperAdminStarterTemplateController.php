<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\Catalog\Models\PlatformStarterTemplate;
use App\Domain\Catalog\Models\StarterTemplateApplication;
use App\Domain\Catalog\Services\StarterTemplateRegistry;
use App\Domain\Catalog\Services\StoreTemplateCapture;
use App\Domain\Catalog\Support\StarterTemplates;
use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\BusinessCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Phase B45 follow-up — Module 07 §101 (platform-managed templates), Module
 * 03 §52 (store template, controlled workflow), Module 30 (Super Admin):
 * Umar Techy staff see every starter template, switch one off, decide which
 * are offered to store owners, save a store's structure as a template, and
 * rename or delete the saved ones. Built-in templates are code: their
 * content is changed by a reviewed release, not here.
 *
 * Platform staff only (route group); every change needs step-up and is
 * audited.
 */
final class SuperAdminStarterTemplateController
{
    public function index(StarterTemplateRegistry $registry): JsonResponse
    {
        $uses = StarterTemplateApplication::query()->withoutTenantScope()
            ->selectRaw('template_key, COUNT(DISTINCT store_id) AS stores')->groupBy('template_key')->pluck('stores', 'template_key');
        $sources = Store::query()->whereIn('id', array_filter(array_column($registry->all(), 'source_store_id')))->pluck('name', 'id');

        return response()->json(['data' => array_values(array_map(fn (array $e) => [
            'key' => $e['key'], 'name' => $e['name'], 'summary' => $e['summary'], 'version' => $e['version'],
            'source' => $e['source'], 'business_category' => $e['business_category'],
            'is_active' => $e['is_active'], 'offered_to_stores' => $e['offered_to_stores'],
            'source_store' => $e['source_store_id'] === null ? null : ['id' => $e['source_store_id'], 'name' => $sources[$e['source_store_id']] ?? null],
            'categories' => count($e['definition']['categories'] ?? []), 'attributes' => count($e['definition']['attributes'] ?? []),
            'brands' => count($e['definition']['brands'] ?? []), 'urdu' => count($e['definition']['ur'] ?? []),
            'stores_using' => (int) ($uses[$e['key']] ?? 0),
        ], $registry->all()))]);
    }

    public function show(string $key, StarterTemplateRegistry $registry): JsonResponse
    {
        $entry = $registry->find($key) ?? abort(404);
        $d = $entry['definition'];
        $names = array_column($d['attributes'] ?? [], 'name', 'key');

        return response()->json(['data' => [
            'key' => $entry['key'], 'name' => $entry['name'], 'summary' => $entry['summary'], 'source' => $entry['source'],
            'categories' => array_map(fn (array $c) => [
                'name' => $c['name'], 'description' => $c['description'] ?? null, 'children' => $c['children'] ?? [],
                'filters' => array_map(fn ($k) => $names[$k] ?? $k, array_keys(array_filter($c['attributes'] ?? [], fn ($spec) => in_array('filter', StarterTemplates::flags((string) $spec), true)))),
            ], $d['categories'] ?? []),
            'attributes' => array_map(fn (array $a) => ['key' => $a['key'], 'name' => $a['name'], 'type' => $a['type'], 'values' => array_map(fn ($v) => is_array($v) ? $v[0] : $v, $a['values'] ?? [])], $d['attributes'] ?? []),
            'brands' => $d['brands'] ?? [],
            'themes' => $d['themes'] ?? [],
            'default_sort' => $d['default_sort'] ?? null,
        ]]);
    }

    /** Built-in: availability only. Saved from a store: also name, summary and what the stores sell. */
    public function update(Request $request, string $key, StarterTemplateRegistry $registry, AuditLogger $audit): JsonResponse
    {
        $entry = $registry->find($key) ?? abort(404);
        $fromStore = $entry['source'] === PlatformStarterTemplate::FROM_STORE;
        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'offered_to_stores' => ['sometimes', 'boolean'],
            'name' => [$fromStore ? 'sometimes' : 'prohibited', 'string', 'max:120'],
            'summary' => [$fromStore ? 'sometimes' : 'prohibited', 'nullable', 'string', 'max:500'],
            'business_category' => [$fromStore ? 'sometimes' : 'prohibited', 'nullable', Rule::in(BusinessCategories::keys())],
        ]);

        $row = PlatformStarterTemplate::query()->firstOrNew(['key' => $key], ['source' => PlatformStarterTemplate::BUILT_IN]);
        $row->fill($data)->save();
        $audit->record('starter_template.updated', ['key' => $key, ...$data], $row, null, $request->user());

        return response()->json(['data' => $registry->find($key)]);
    }

    /** Only templates saved from a store; stores that used one keep what it added. */
    public function destroy(Request $request, string $key, AuditLogger $audit): Response
    {
        $row = PlatformStarterTemplate::query()->where('key', $key)->where('source', PlatformStarterTemplate::FROM_STORE)->first() ?? abort(404);
        $audit->record('starter_template.deleted', ['key' => $key, 'name' => $row->name], $row, null, $request->user());
        $row->delete();

        return response()->noContent();
    }

    public function saveFromStore(Request $request, Store $store, StoreTemplateCapture $capture): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'summary' => ['nullable', 'string', 'max:500'],
            'business_category' => ['nullable', Rule::in(BusinessCategories::keys())],
        ]);
        $saved = $capture->save($store, $request->user(), $data['name'], $data['summary'] ?? null, $data['business_category'] ?? null);

        return response()->json(['data' => [
            'key' => $saved['template']->key, 'name' => $saved['template']->name,
            'skipped_deeper_categories' => $saved['skipped_deeper_categories'],
        ]], 201);
    }
}
