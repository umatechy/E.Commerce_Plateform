<?php

declare(strict_types=1);

namespace App\Domain\Seo\Models;

/** Module 16 §5 "SEO Scope" — only the 4 targets B13 implements (see docs/development/b13-inspection-findings.md "Scope Decision"). */
enum SeoableType: string
{
    case Store = 'store'; // the store's own "home page" SEO
    case Product = 'product';
    case Category = 'category';
    case Brand = 'brand';
    case ContentPage = 'content_page';
}
