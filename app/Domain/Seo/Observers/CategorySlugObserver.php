<?php

declare(strict_types=1);

namespace App\Domain\Seo\Observers;

use App\Domain\Catalog\Models\Category;
use App\Domain\Seo\Services\RedirectService;

final class CategorySlugObserver
{
    public function updated(Category $category): void
    {
        if (! $category->wasChanged('slug')) {
            return;
        }

        app(RedirectService::class)->recordSlugChange(
            'category', $category->id,
            'categories/'.$category->getOriginal('slug'),
            'categories/'.$category->slug,
        );
    }
}
