<?php

declare(strict_types=1);

namespace App\Domain\Seo\Observers;

use App\Domain\Catalog\Models\Product;
use App\Domain\Seo\Services\RedirectService;

/**
 * Module 16 §11 "Slug Changes" — additive observer, NEVER modifies
 * ProductService's own update logic (this milestone's own explicit
 * instruction: "reuse established architecture... never create a
 * parallel architecture unnecessarily," applied here as "never rewrite
 * an existing domain's update path just to bolt on a redirect").
 */
final class ProductSlugObserver
{
    public function updated(Product $product): void
    {
        if (! $product->wasChanged('slug')) {
            return;
        }

        app(RedirectService::class)->recordSlugChange(
            'product', $product->id,
            'products/'.$product->getOriginal('slug'),
            'products/'.$product->slug,
        );
    }
}
