<?php

declare(strict_types=1);

namespace App\Domain\Theme\Models;

/**
 * Module 17 §21-25 "Layout Engine / Header / Footer / Navigation /
 * Homepage Section System" — the module's own example list, used as
 * a fixed whitelist. Non-Negotiable Step 19: never a free-text
 * component name — an unrecognized type is rejected by
 * ThemeConfigValidator before storage, so there is no code path that
 * maps an unknown string to anything at all.
 */
enum SectionType: string
{
    case AnnouncementBar = 'announcement_bar';
    case Header = 'header';
    case Hero = 'hero';
    case FeaturedProducts = 'featured_products';
    case FeaturedCategories = 'featured_categories';
    case PromotionalBanner = 'promotional_banner';
    case Newsletter = 'newsletter';
    case Footer = 'footer';
}
