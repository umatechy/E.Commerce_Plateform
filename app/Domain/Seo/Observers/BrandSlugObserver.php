<?php

declare(strict_types=1);

namespace App\Domain\Seo\Observers;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Seo\Services\RedirectService;

final class BrandSlugObserver
{
    public function updated(Brand $brand): void
    {
        if (! $brand->wasChanged('slug')) {
            return;
        }

        app(RedirectService::class)->recordSlugChange(
            'brand', $brand->id,
            'brands/'.$brand->getOriginal('slug'),
            'brands/'.$brand->slug,
        );
    }
}
