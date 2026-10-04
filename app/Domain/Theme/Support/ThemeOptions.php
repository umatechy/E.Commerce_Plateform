<?php

declare(strict_types=1);

namespace App\Domain\Theme\Support;

use App\Domain\Theme\Models\SectionType;

/**
 * Module 17 §10–12, §21–26, §33, §40 and Module 18 §8, §28 (Phase B36): the
 * fixed set of values a store's theme configuration may hold, and which
 * package feature each non-basic value needs (Module 04 §32–33 — Basic
 * essential, Business advanced, Premium premium; owner decision 2026-10-04:
 * premium layouts, premium animation and section reordering are Premium).
 *
 * ThemeConfigValidator checks the shape against these lists;
 * ThemeEntitlements checks the package; ThemeResolver falls back for a store
 * whose package no longer includes a value. Nothing outside these lists is
 * ever stored or rendered.
 */
final class ThemeOptions
{
    public const COLOR_TOKENS = ['primary', 'secondary', 'accent', 'background', 'surface', 'text', 'muted', 'border', 'success', 'warning', 'error'];

    /** Self-hosted fonts (no third-party font requests — Module 17 §8 "privacy-aware"); system-ui and Georgia need no file. */
    public const FONTS = ['system-ui', 'Inter', 'Roboto', 'Poppins', 'Montserrat', 'DM Sans', 'Georgia', 'Playfair Display', 'Merriweather', 'Lora'];

    public const RADIUS = ['none', 'sm', 'md', 'lg'];
    public const SHADOW = ['none', 'subtle', 'medium', 'strong'];
    public const DENSITY = ['compact', 'standard', 'comfortable'];

    /** @var array<string, list<string|int>> layout field => allowed values */
    public const LAYOUT = [
        'header_style' => ['classic', 'minimal', 'centered', 'split'],
        'container' => ['narrow', 'standard', 'wide'],
        'product_card' => ['standard', 'minimal', 'elevated', 'overlay'],
        'grid_columns' => [3, 4],
        'hero_style' => ['simple', 'centered', 'split', 'fullbleed'],
        'footer_style' => ['simple', 'columns'],
    ];

    public const LAYOUT_BOOLEANS = ['sticky_header'];

    /** Premium layouts (`layout.advanced`). */
    public const PREMIUM_LAYOUT = [
        'header_style' => ['centered', 'split'],
        'product_card' => ['elevated', 'overlay'],
        'hero_style' => ['split', 'fullbleed'],
    ];

    /** Module 18 §8 profiles; CUSTOM is not offered (custom motion needs its own schema — §41). */
    public const MOTION_PROFILES = ['none', 'minimal', 'standard', 'premium', 'playful'];
    public const MOTION_INTENSITY = ['low', 'medium', 'high'];
    public const MOTION_BOOLEANS = ['reveal_on_scroll', 'hover_effects'];

    /** @var array<string, string> profile => feature it needs (none and minimal need none) */
    public const MOTION_PROFILE_FEATURE = ['standard' => 'animation.advanced', 'premium' => 'animation.premium', 'playful' => 'animation.premium'];

    /** Sections every package has; the rest need `homepage.advanced_sections`. */
    public const BASIC_SECTIONS = [
        SectionType::AnnouncementBar, SectionType::Header, SectionType::Hero, SectionType::FeaturedProducts,
        SectionType::FeaturedCategories, SectionType::PromotionalBanner, SectionType::Newsletter, SectionType::Footer,
    ];

    /**
     * The fixed home page order of a store that may not reorder sections
     * (`homepage.reorder`): sections of the same type keep their own order.
     */
    public const CANONICAL_ORDER = [
        'announcement_bar', 'header', 'hero', 'trust_badges', 'featured_categories', 'featured_products', 'best_sellers',
        'sale_products', 'promotional_banner', 'featured_brands', 'testimonials', 'rich_text', 'faq', 'newsletter', 'footer',
    ];

    /** Icons a trust badge may show — drawn by the storefront, never uploaded. */
    public const BADGE_ICONS = ['delivery', 'cod', 'returns', 'secure', 'support', 'quality'];

    public static function isBasicSection(string $type): bool
    {
        return in_array($type, array_map(fn (SectionType $t) => $t->value, self::BASIC_SECTIONS), true);
    }
}
