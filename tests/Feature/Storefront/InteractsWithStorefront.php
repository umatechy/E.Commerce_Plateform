<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\Subscription;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;

/** Shared set-up for the Module 05 (Phase B24) storefront tests. */
trait InteractsWithStorefront
{
    /** A launched store with an active subscription, resolved as the test's tenant. */
    protected function openStore(array $attributes = [], string $subscriptionStatus = 'active'): Store
    {
        $store = Store::factory()->create(['status' => 'active', ...$attributes]);
        Subscription::factory()->for($store)->for(Package::factory())->create(['status' => $subscriptionStatus]);
        $this->inStore($store);

        return $store;
    }

    protected function inStore(Store $store): void
    {
        app(TenantContext::class)->resolveToStore($store->id);
    }

    /** An active, public product (override anything). */
    protected function product(Store $store, array $attributes = []): Product
    {
        return Product::factory()->create(['store_id' => $store->id, 'status' => 'active', 'visibility' => 'public', ...$attributes]);
    }

    protected function variant(Product $product, array $options, int $priceMinor, array $attributes = []): ProductVariant
    {
        return ProductVariant::query()->create([
            'store_id' => $product->store_id, 'product_id' => $product->id, 'sku' => strtoupper(uniqid('V-')),
            'price_minor' => $priceMinor, 'status' => 'active', 'option_values' => $options, ...$attributes,
        ]);
    }

    /** Stock in the store's default warehouse (created with every store). */
    protected function stock(Product $product, ?ProductVariant $variant, int $onHand, int $reserved = 0): void
    {
        Inventory::query()->create([
            'store_id' => $product->store_id,
            'warehouse_id' => Warehouse::query()->withoutTenantScope()->where('store_id', $product->store_id)->where('is_default', true)->value('id'),
            'product_id' => $variant === null ? $product->id : null,
            'product_variant_id' => $variant?->id,
            'on_hand' => $onHand,
            'reserved' => $reserved,
        ]);
    }

    /** @return array<string, string> */
    protected function storefront(Store $store): array
    {
        return ['X-Store-Slug' => $store->slug];
    }
}
