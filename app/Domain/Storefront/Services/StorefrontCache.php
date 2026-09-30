<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Module 05 — per-store cache of public catalog data (the caching
 * Phase B3 deferred "until a storefront exists").
 *
 * Keys embed a per-store version number. Any change to a store's
 * catalog, stock, pages, theme or settings bumps that store's version
 * (StorefrontCacheObserver), so every key of the old version is simply
 * never read again — no wildcard deletes, no cross-store effect, and it
 * works on every cache driver. The TTL only bounds memory.
 */
final class StorefrontCache
{
    /**
     * @template T
     * @param callable(): T $compute
     * @return T
     */
    public function remember(int $storeId, string $key, callable $compute): mixed
    {
        return Cache::remember(
            "storefront:{$storeId}:v{$this->version($storeId)}:{$key}",
            (int) config('storefront.cache_ttl_seconds'),
            $compute,
        );
    }

    public function version(int $storeId): int
    {
        return (int) Cache::get($this->versionKey($storeId), 0);
    }

    public function bump(int $storeId): void
    {
        Cache::add($this->versionKey($storeId), 0);
        Cache::increment($this->versionKey($storeId));
    }

    private function versionKey(int $storeId): string
    {
        return "storefront:{$storeId}:version";
    }
}
