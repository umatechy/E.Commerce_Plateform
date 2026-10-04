<?php

declare(strict_types=1);

namespace App\Domain\Storefront\Support;

use App\Domain\Catalog\Models\Product;
use App\Domain\Settings\Services\TranslationService;
use App\Domain\Storefront\Services\StorefrontLocale;

/**
 * Phase B38: store content in the shopper's language for places outside the
 * storefront presenter (cart lines, wishlist). Outside a storefront request
 * the language is the default one, so the original text is returned.
 */
final class StorefrontText
{
    public static function productName(?Product $product): ?string
    {
        if ($product === null) {
            return null;
        }
        $locale = app(StorefrontLocale::class);

        return app(TranslationService::class)->value('product', $product->id, 'name', $product->name, $locale->current(), $locale->default());
    }
}
