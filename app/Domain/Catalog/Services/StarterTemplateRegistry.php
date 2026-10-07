<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\PlatformStarterTemplate;
use App\Domain\Catalog\Support\StarterTemplates;

/**
 * Phase B45 follow-up — Module 07 §101, §106: every starter template in one
 * list — the built-in ones (code, StarterTemplates) with the availability
 * staff gave them, and the ones staff saved from a store
 * (platform_starter_templates).
 *
 * An entry: key, name, summary, version, business_category, source
 * (built_in | store), is_active, offered_to_stores, definition (the
 * categories, attributes, themes, order, brands and its own Urdu glossary).
 *
 * @phpstan-type Entry array{key: string, name: string, summary: string, version: int, business_category: ?string, source: string, is_active: bool, offered_to_stores: bool, definition: array<string, mixed>, source_store_id: ?int}
 */
final class StarterTemplateRegistry
{
    /** @return array<string, Entry> every template, built-in first */
    public function all(): array
    {
        $rows = PlatformStarterTemplate::query()->orderBy('id')->get()->keyBy('key');
        $entries = [];
        foreach (StarterTemplates::keys() as $key) {
            $t = StarterTemplates::get($key);
            /** @var PlatformStarterTemplate|null $row */
            $row = $rows->get($key);
            $entries[$key] = [
                'key' => $key, 'name' => $t['name'], 'summary' => $t['summary'], 'version' => $t['version'],
                'business_category' => $key, 'source' => PlatformStarterTemplate::BUILT_IN,
                'is_active' => $row->is_active ?? true, 'offered_to_stores' => $row->offered_to_stores ?? true,
                'definition' => $t, 'source_store_id' => null,
            ];
        }
        foreach ($rows as $key => $row) {
            if ($row->source !== PlatformStarterTemplate::FROM_STORE || $row->definition === null) {
                continue;
            }
            $entries[$key] = [
                'key' => $key, 'name' => (string) $row->name, 'summary' => (string) $row->summary, 'version' => $row->version,
                'business_category' => $row->business_category, 'source' => PlatformStarterTemplate::FROM_STORE,
                'is_active' => $row->is_active, 'offered_to_stores' => $row->offered_to_stores,
                'definition' => $row->definition, 'source_store_id' => $row->source_store_id,
            ];
        }

        return $entries;
    }

    /** @return Entry|null */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * A template that may be used: active, and for a store's own staff also
     * offered to stores (staff creating a store may use any active one).
     *
     * @return Entry|null
     */
    public function usable(string $key, bool $byStoreStaff): ?array
    {
        $entry = $this->find($key);

        return $entry !== null && $entry['is_active'] && (! $byStoreStaff || $entry['offered_to_stores']) ? $entry : null;
    }

    /** @return array<string, Entry> what a store's staff can choose from */
    public function offered(): array
    {
        return array_filter($this->all(), fn (array $e) => $e['is_active'] && $e['offered_to_stores']);
    }

    /** The template for what a store sells: the built-in one when available, else an offered saved one for that category. */
    public function forBusinessCategory(?string $category): ?string
    {
        if ($category === null) {
            return null;
        }
        $offered = $this->offered();
        if (isset($offered[$category]) && $offered[$category]['source'] === PlatformStarterTemplate::BUILT_IN) {
            return $category;
        }
        foreach ($offered as $key => $entry) {
            if ($entry['business_category'] === $category) {
                return $key;
            }
        }

        return null;
    }
}
