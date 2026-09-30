<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Observers;

use App\Domain\Storefront\Services\StorefrontCache;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Database\Eloquent\Model;

/**
 * Invalidates a store's storefront cache whenever something shoppers
 * can see changes: registered on catalog, stock, page, theme, SEO and
 * store-setting models (AppServiceProvider). Bulk query-builder updates
 * fire no model events; those are bounded by the cache TTL, and cart
 * and checkout always re-check stock and price live.
 */
final class StorefrontCacheObserver
{
    public function saved(Model $model): void
    {
        $this->bump($model);
    }

    public function deleted(Model $model): void
    {
        $this->bump($model);
    }

    public function restored(Model $model): void
    {
        $this->bump($model);
    }

    private function bump(Model $model): void
    {
        $storeId = $model instanceof Store ? $model->id : $model->getAttribute('store_id');

        if ($storeId !== null) {
            app(StorefrontCache::class)->bump((int) $storeId);
        }
    }
}
