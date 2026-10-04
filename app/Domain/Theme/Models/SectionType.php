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
 *
 * Phase B36 added the rest of §25's list that the platform has data for:
 * best sellers, products on sale, brands, testimonials, FAQ, a text
 * block and trust badges ("advanced sections", Business and Premium).
 * Collections, a product carousel and blog wait for their modules.
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
    case BestSellers = 'best_sellers';
    case SaleProducts = 'sale_products';
    case FeaturedBrands = 'featured_brands';
    case Testimonials = 'testimonials';
    case Faq = 'faq';
    case RichText = 'rich_text';
    case TrustBadges = 'trust_badges';
}
